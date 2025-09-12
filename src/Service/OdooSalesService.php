<?php
declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Couche Odoo (ventes/logistique) :
 * - fetch sale.order (+partner, lignes, pickings, agrégats demandé/préparé)
 * - refresh des lignes locales (préserve prepared_qty)
 * - actions : confirmer / annuler
 * - push des quantités préparées sur stock.move + validation picking (robuste)
 */
final class OdooSalesService
{
    public function __construct(private readonly OdooClient $rpc) {}

    /** Liste minimale pour batch import */
    public function listSaleOrdersMinimal(array $domain, int $limit = 500, int $offset = 0): array
    {
        return $this->rpc->callKW('sale.order', 'search_read', [$domain], [
            'fields' => ['id','name'],
            'limit'  => $limit,
            'offset' => $offset,
            'order'  => 'id asc',
        ]);
    }

    /** Liste des pickings (stock.picking), même auth que orders via OdooClient */
    public function listPickings(
        array $domain,
        int $limit = 500,
        int $offset = 0,
        array $fields = ['name','origin','partner_id','scheduled_date','state','write_date']
    ): array {
        return $this->rpc->callKW('stock.picking', 'search_read', [$domain], [
            'fields' => $fields,
            'limit'  => $limit,
            'offset' => $offset,
            'order'  => 'scheduled_date desc',
        ]);
    }

    /** Récupère SO + partner livraison + lignes + pickings + agrégats demandé/préparé */
    public function fetchSaleOrder(string|int $idOrName): array
    {
        $domain = is_numeric($idOrName)
            ? [['id', '=', (int)$idOrName]]
            : [['name', '=', (string)$idOrName]];

        $soList = $this->rpc->callKW('sale.order', 'search_read', [$domain], [
            'fields' => [
                'id','name','state','date_order',
                'partner_id','partner_shipping_id',
                'picking_ids','company_id','note',
                'amount_total','currency_id','delivery_status',
            ],
            'limit' => 1
        ]);
        if (!$soList) {
            throw new \RuntimeException('Odoo: sale.order introuvable');
        }
        $so = $soList[0];

        // Partner livraison
        $shipId = is_array($so['partner_shipping_id']) ? ($so['partner_shipping_id'][0] ?? null) : $so['partner_shipping_id'];
        $partner = null;
        if ($shipId) {
            $p = $this->rpc->read('res.partner', [$shipId], ['name','street','street2','zip','city','country_id','phone','email']);
            $partner = $p[0] ?? null;
            if ($partner && is_array($partner['country_id'])) {
                $cc = $this->rpc->read('res.country', [$partner['country_id'][0]], ['code']);
                $partner['country_code'] = $cc[0]['code'] ?? null;
            }
        }

        // Lignes
        $lines = $this->rpc->callKW('sale.order.line','search_read', [[['order_id', '=', $so['id']], ['display_type', '=', false]]], [
            'fields' => ['id','product_id','product_uom_qty','product_uom','name'],
            'limit'  => 1000
        ]);

        // Pickings
        if (!empty($so['picking_ids'])) {
            $pickings = $this->rpc->read('stock.picking', $so['picking_ids'], [
                'id','name','origin','state','picking_type_id','scheduled_date'
            ]);
        } else {
            $pickings = $this->rpc->callKW('stock.picking','search_read', [[['origin', '=', $so['name']]]], [
                'fields' => ['id','name','state'],
                'limit'  => 100
            ]);
        }

        // Agrégats : demandé via move.product_uom_qty, préparé via move.line qty (si dispo)
        $moveSum = [];
        if (!empty($pickings)) {
            $pickingIds = array_map(fn($p) => $p['id'], $pickings);

            $moves = $this->rpc->callKW('stock.move','search_read', [[['picking_id','in',$pickingIds]]], [
                'fields' => ['id','product_id','product_uom_qty','move_line_ids'],
                'limit'  => 2000
            ]);

            $allMlIds = [];
            foreach ($moves as $m) {
                $pid = is_array($m['product_id']) ? ($m['product_id'][0] ?? null) : $m['product_id'];
                if ($pid) {
                    $moveSum[$pid]['demanded'] = ($moveSum[$pid]['demanded'] ?? 0.0) + (float)$m['product_uom_qty'];
                }
                if (!empty($m['move_line_ids'])) {
                    foreach ($m['move_line_ids'] as $mlId) { $allMlIds[] = $mlId; }
                }
            }

            if ($allMlIds) {
                $doneByPid = $this->sumMoveLineDoneByProduct($allMlIds); // tente qty_done puis quantity_done
                foreach ($doneByPid as $pid => $done) {
                    $moveSum[$pid]['done'] = ($moveSum[$pid]['done'] ?? 0.0) + $done;
                }
            }
        }

        return compact('so','partner','lines','pickings','moveSum');
    }

    /** Actions SO */
    public function confirmSaleOrder(int $saleOrderId): void
    { $this->rpc->callKW('sale.order','action_confirm', [[$saleOrderId]]); }

    public function cancelSaleOrder(int $saleOrderId): void
    { $this->rpc->callKW('sale.order','action_cancel', [[$saleOrderId]]); }

    /** Rafraîchir les lignes locales en préservant prepared_qty */
    public function refreshLocalOrderLines(Connection $db, int $localOrderId): void
    {
        $local = $db->fetchAssociative('SELECT odoo_sale_order_id FROM sales_orders WHERE id=?', [$localOrderId]);
        if (!$local || !$local['odoo_sale_order_id']) { throw new \RuntimeException('Aucun odoo_sale_order_id'); }
        $soId = (int)$local['odoo_sale_order_id'];

        $lines = $this->rpc->callKW('sale.order.line','search_read', [[['order_id', '=', $soId], ['display_type', '=', false]]], [
            'fields' => ['id','product_id','product_uom_qty','name'],
            'limit'  => 2000
        ]);

        // Stock dispo
        $pids = [];
        foreach ($lines as $l) {
            $pid = is_array($l['product_id']) ? ($l['product_id'][0] ?? null) : null;
            if ($pid) $pids[$pid] = true;
        }
        $qtyMap = [];
        if ($pids) {
            $prods = $this->rpc->read('product.product', array_keys($pids), ['qty_available']);
            foreach ($prods as $p) { $qtyMap[(int)$p['id']] = (float)$p['qty_available']; }
        }

        // Préserver prepared_qty
        $existing = $db->fetchAllAssociative('SELECT odoo_line_id, prepared_qty FROM sales_order_lines WHERE order_id=?', [$localOrderId]);
        $preparedByLine = [];
        foreach ($existing as $e) { if ($e['odoo_line_id']) $preparedByLine[(int)$e['odoo_line_id']] = $e['prepared_qty']; }

        // Purge + insert
        $db->executeStatement('DELETE FROM sales_order_lines WHERE order_id=?', [$localOrderId]);
        foreach ($lines as $l) {
            $lineId = (int)$l['id'];
            $pid    = is_array($l['product_id']) ? ($l['product_id'][0] ?? null) : null;
            $pname  = is_array($l['product_id']) ? ($l['product_id'][1] ?? ($l['name'] ?? '')) : ($l['name'] ?? '');
            $prepared = $preparedByLine[$lineId] ?? null;

            $db->executeStatement(
                'INSERT INTO sales_order_lines
                    (order_id, odoo_line_id, odoo_product_id, sku, name, qty, unit_price, prepared_qty, odoo_qty_available)
                 VALUES (?,?,?,?,?,?,0,?,?)',
                [
                    $localOrderId, $lineId, $pid,
                    (string)$pname, (string)$pname, (float)$l['product_uom_qty'],
                    $prepared !== null ? (float)$prepared : null,
                    $pid ? ($qtyMap[$pid] ?? null) : null
                ]
            );
        }
    }

    /**
     * Pousse les quantités préparées LOCALES vers Odoo **sur stock.move** (pas move.line),
     * puis valide le picking (gère immediate transfer & backorder en automatique).
     */
    public function pushPreparedAndValidate(Connection $db, int $localOrderId, bool $createBackorder = true): void
    {
        $row = $db->fetchAssociative('SELECT odoo_sale_order_id, odoo_name FROM sales_orders WHERE id=?', [$localOrderId]);
        if (!$row || !$row['odoo_sale_order_id']) {
            throw new \RuntimeException('Aucun odoo_sale_order_id');
        }
        $soId   = (int)$row['odoo_sale_order_id'];
        $soName = (string)$row['odoo_name'];

        // 1) Lire SO (état + pickings)
        $soRead = $this->rpc->callKW('sale.order', 'read', [[$soId], ['state','picking_ids','name']]);
        $state = $soRead[0]['state'] ?? null;
        $pickingIds = $soRead[0]['picking_ids'] ?? [];

        // Auto-confirm optionnelle
        $autoConfirm = !empty($_ENV['ODOO_AUTOCONFIRM_ON_PUSH']);
        if ((!$pickingIds || in_array($state, ['draft','sent'], true)) && $autoConfirm) {
            $this->rpc->callKW('sale.order', 'action_confirm', [[$soId]]);
            $soRead = $this->rpc->callKW('sale.order', 'read', [[$soId], ['state','picking_ids','name']]);
            $state = $soRead[0]['state'] ?? null;
            $pickingIds = $soRead[0]['picking_ids'] ?? [];
        }

        // 2) Sélection picking : priorité picking_ids ; fallback origin=name
        if ($pickingIds) {
            $pickings = $this->rpc->read('stock.picking', $pickingIds, [
                'id','name','state','location_id','location_dest_id'
            ]);
        } else {
            $pickings = $this->rpc->callKW('stock.picking','search_read', [[['origin', '=', $soName]]], [
                'fields' => ['id','name','state','location_id','location_dest_id'],
                'limit'  => 20
            ]);
        }

        if (!$pickings) {
            if (in_array($state, ['draft','sent'], true)) {
                throw new \RuntimeException('Aucun picking lié : la vente est encore en "' . $state . '". Confirme d’abord.');
            }
            throw new \RuntimeException('Aucun picking lié (origin différent / routes non générées).');
        }

        // Privilégier un picking non done/cancel
        $candidates = array_values(array_filter($pickings, fn($p) => !in_array($p['state'], ['done','cancel'], true)));
        $pick = $candidates[0] ?? $pickings[0];
        $pickingId = (int)$pick['id'];

        // 3) Assigner (réservations)
        $this->rpc->callKW('stock.picking', 'action_assign', [[$pickingId]]);

        // 4) Cibles locales (prepared_qty par product_id)
        $rows = $db->fetchAllAssociative(
            'SELECT odoo_product_id, prepared_qty FROM sales_order_lines WHERE order_id=? AND prepared_qty IS NOT NULL',
            [$localOrderId]
        );
        $target = [];
        foreach ($rows as $r) {
            $pid = (int)($r['odoo_product_id'] ?? 0);
            $q   = (float)($r['prepared_qty'] ?? 0);
            if ($pid && $q > 0) {
                $target[$pid] = ($target[$pid] ?? 0.0) + $q;
            }
        }
        if (!$target) {
            throw new \RuntimeException('Aucune quantité préparée à pousser');
        }

        // 5) Moves du picking (on veut id, product_id, product_uom_qty, quantity_done si dispo)
        $hasMoveQtyDone = $this->modelHasField('stock.move','quantity_done');
        $fields = ['id','product_id','product_uom','product_uom_qty','move_line_ids'];
        if ($hasMoveQtyDone) { $fields[] = 'quantity_done'; }
        $moves = $this->rpc->callKW('stock.move', 'search_read', [[['picking_id', '=', $pickingId]]], [
            'fields' => $fields,
            'limit'  => 2000
        ]);

        // Index: liste de moves par produit + restant par move et par produit
        $movesByPid = [];
        $remainingByMoveId = [];
        $remainingByPid = [];

        // somme done depuis lines si besoin
        $allMlIds = [];
        foreach ($moves as $m) {
            if (!empty($m['move_line_ids'])) {
                foreach ($m['move_line_ids'] as $mlId) { $allMlIds[] = $mlId; }
            }
        }
        $doneFromLines = $allMlIds ? $this->sumMoveLineDoneByProduct($allMlIds) : [];

        foreach ($moves as $m) {
            $pid = $this->idFromMany2One($m['product_id'] ?? null);
            if (!$pid) { continue; }

            $demanded = (float)($m['product_uom_qty'] ?? 0.0);
            $done = 0.0;
            if ($hasMoveQtyDone && isset($m['quantity_done'])) {
                $done = (float)$m['quantity_done'];
            } else {
                // valeur agrégée produit si le champ n'existe pas: on répartira par move via les lines en mode additif
                $done = 0.0;
            }

            $remaining = max(0.0, $demanded - $done);
            $movesByPid[$pid][] = $m;
            $remainingByMoveId[(int)$m['id']] = $remaining;
            $remainingByPid[$pid] = ($remainingByPid[$pid] ?? 0.0) + $remaining;
        }

        // 6) Appliquer les quantités plafonnées et écrire quantity_done (ou move lines) en DISTRIBUANT sur tous les moves
        $hadRemainingAfterWrite = false;
        foreach ($target as $pid => $qty) {
            if (empty($movesByPid[$pid])) { continue; }

            // quantité totale autorisée pour ce produit
            $allowedPid = (float)($remainingByPid[$pid] ?? 0.0);
            $toAllocate = (float)min((float)$qty, $allowedPid);
            if ($toAllocate < 0) { $toAllocate = 0.0; }

            foreach ($movesByPid[$pid] as $m) {
                if ($toAllocate <= 1e-9) { break; }

                $moveId = (int)$m['id'];
                $allowedMove = (float)($remainingByMoveId[$moveId] ?? 0.0);
                if ($allowedMove <= 1e-9) { continue; }

                $alloc = (float)min($toAllocate, $allowedMove);
                $toAllocate -= $alloc;

                // quantité actuelle "done" si dispo
                $currentDone = ($hasMoveQtyDone && isset($m['quantity_done'])) ? (float)$m['quantity_done'] : null;

                try {
                    if ($currentDone !== null) {
                        $newVal = $currentDone + $alloc;
                        $this->rpc->callKW('stock.move','write', [[$moveId], ['quantity_done' => $newVal]]);
                    } else {
                        // fallback: additif sur move lines
                        $this->updateMoveLinesQuantityDone($moveId, $alloc);
                    }
                } catch (\Throwable) {
                    // repli move lines si écriture sur move échoue
                    $this->updateMoveLinesQuantityDone($moveId, $alloc);
                }

                // mettre à jour le restant du move
                $remainingByMoveId[$moveId] = max(0.0, $allowedMove - $alloc);
            }

            // S'il reste quelque chose non alloué, c'est du restant → backorder
            if ($toAllocate > 1e-9) { $hadRemainingAfterWrite = true; }
        }

        // 7) Valider le picking
        try {
            $res = $this->rpc->callKW('stock.picking', 'button_validate', [[$pickingId]]);
        } catch (\Throwable $e) {
            // Fallback: en cas d'erreur (ex. “unhashable type: 'list'”/stocks impossibles),
            // on force la création d'un reliquat via le wizard backorder.
            try {
                $ctx = ['active_model' => 'stock.picking', 'active_ids' => [$pickingId], 'active_id' => $pickingId];
                $wizId = $this->rpc->callKW('stock.backorder.confirmation','create', [[]], ['context' => $ctx]);
                $this->rpc->callKW('stock.backorder.confirmation','process', [[$wizId]], ['context' => $ctx]);
                return; // reliquat créé, on termine proprement
            } catch (\Throwable) {
                // Si le fallback échoue, on relance l'erreur initiale pour diagnostic
                throw $e;
            }
        }

        // a) Immediate transfer wizard
        if (is_array($res) && ($res['res_model'] ?? '') === 'stock.immediate.transfer') {
            if (!empty($res['res_id'])) {
                $this->rpc->callKW('stock.immediate.transfer','process', [[$res['res_id']]]);
            } else {
                // Pas de res_id → on recrée le wizard proprement
                $wizId = $this->rpc->callKW('stock.immediate.transfer','create', [['pick_ids' => [$pickingId]]]);
                $this->rpc->callKW('stock.immediate.transfer','process', [[$wizId]]);
            }
            // Une fois traité, le picking passe en done, sinon on continue
        }

        // b) Backorder wizard → accepter (créer un reliquat) ou annuler (pas de reliquat)
        if (is_array($res) && ($res['res_model'] ?? '') === 'stock.backorder.confirmation') {
            if (!empty($res['res_id'])) {
                $this->rpc->callKW('stock.backorder.confirmation', $createBackorder ? 'process' : 'process_cancel', [[$res['res_id']]]);
            } else {
                // Recréation au besoin
                $ctx = ['active_model' => 'stock.picking', 'active_ids' => [$pickingId], 'active_id' => $pickingId];
                $wizId = $this->rpc->callKW('stock.backorder.confirmation','create', [[]], ['context' => $ctx]);
                $this->rpc->callKW('stock.backorder.confirmation', $createBackorder ? 'process' : 'process_cancel', [[$wizId]], ['context' => $ctx]);
            }
        } elseif ($createBackorder && $hadRemainingAfterWrite) {
            // Pas de wizard retourné mais des restants: forcer la création d'un backorder
            try {
                $ctx = ['active_model' => 'stock.picking', 'active_ids' => [$pickingId], 'active_id' => $pickingId];
                $wizId = $this->rpc->callKW('stock.backorder.confirmation','create', [[]], ['context' => $ctx]);
                $this->rpc->callKW('stock.backorder.confirmation','process', [[$wizId]], ['context' => $ctx]);
            } catch (\Throwable) {
                // on n'empêche pas la suite si le fallback échoue silencieusement
            }
        }
    }

    // ===================== Helpers =====================

    /** Somme qty_done/quantity_done par product_id sur des move_line_ids (tolérant) */
    private function sumMoveLineDoneByProduct(array $moveLineIds): array
    {
        $out = [];
        $ids = array_values(array_unique($moveLineIds));
        $chunks = array_chunk($ids, 200);

        foreach ($chunks as $chunk) {
            $mls = null;
            try {
                $mls = $this->rpc->read('stock.move.line', $chunk, ['id','product_id','qty_done']);
            } catch (\Throwable $e) {
                try {
                    $mls = $this->rpc->read('stock.move.line', $chunk, ['id','product_id','quantity_done']);
                } catch (\Throwable $e2) {
                    $mls = [];
                }
            }
            foreach ($mls as $ml) {
                $pid = is_array($ml['product_id']) ? ($ml['product_id'][0] ?? null) : $ml['product_id'];
                if (!$pid) { continue; }
                $val = 0.0;
                if (isset($ml['qty_done']))          { $val = (float)$ml['qty_done']; }
                elseif (isset($ml['quantity_done'])) { $val = (float)$ml['quantity_done']; }
                $out[$pid] = ($out[$pid] ?? 0.0) + $val;
            }
        }
        return $out;
    }

    /** Teste la présence d’un champ via fields_get */
    private function modelHasField(string $model, string $field): bool
    {
        try {
            $fields = $this->rpc->callKW($model, 'fields_get', [[], ['attributes' => []]]);
            return is_array($fields) && array_key_exists($field, $fields);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Extrait l’ID d’un many2one (id ou [id, label]) */
    private function idFromMany2One(mixed $m2o): ?int
    {
        if (is_array($m2o)) { return isset($m2o[0]) ? (int)$m2o[0] : null; }
        if (is_int($m2o))   { return $m2o; }
        return null;
    }

    /**
     * Retourne, pour une vente (id numérique ou name), le restant à préparer par product_id,
     * calculé comme somme(product_uom_qty des moves) - somme(des quantités déjà faites).
     */
    public function getRemainingByProductForOrder(int|string $idOrName): array
    {
        $data = $this->fetchSaleOrder($idOrName);
        $pickings = (array)($data['pickings'] ?? []);
        if (!$pickings) { return []; }

        $pickingIds = [];
        foreach ($pickings as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid > 0) { $pickingIds[] = $pid; }
        }
        if (!$pickingIds) { return []; }

        // Lire les moves de tous les pickings
        $hasMoveQtyDone = $this->modelHasField('stock.move','quantity_done');
        $fields = ['id','product_id','product_uom_qty','move_line_ids'];
        if ($hasMoveQtyDone) { $fields[] = 'quantity_done'; }

        $moves = $this->rpc->callKW('stock.move','search_read', [[['picking_id','in',$pickingIds]]], [
            'fields' => $fields,
            'limit'  => 5000
        ]);

        $demandedByPid = [];
        $doneByPid = [];

        $allMlIds = [];
        foreach ($moves as $m) {
            $pid = $this->idFromMany2One($m['product_id'] ?? null);
            if ($pid) {
                $demandedByPid[$pid] = ($demandedByPid[$pid] ?? 0.0) + (float)($m['product_uom_qty'] ?? 0.0);
            }
            if (!empty($m['move_line_ids'])) {
                foreach ($m['move_line_ids'] as $mlId) { $allMlIds[] = $mlId; }
            }
            if ($hasMoveQtyDone && isset($m['quantity_done']) && $pid) {
                $doneByPid[$pid] = ($doneByPid[$pid] ?? 0.0) + (float)$m['quantity_done'];
            }
        }

        if (!$hasMoveQtyDone && $allMlIds) {
            $fromLines = $this->sumMoveLineDoneByProduct($allMlIds);
            foreach ($fromLines as $pid => $q) {
                $doneByPid[(int)$pid] = ($doneByPid[(int)$pid] ?? 0.0) + (float)$q;
            }
        }

        $remaining = [];
        foreach ($demandedByPid as $pid => $dem) {
            $done = (float)($doneByPid[$pid] ?? 0.0);
            $rest = $dem - $done;
            if ($rest < 0) { $rest = 0.0; }
            $remaining[(int)$pid] = $rest;
        }
        return $remaining;
    }

    /**
     * Met à jour la quantité réalisée via stock.move.line (qty_done/quantity_done) pour un move donné.
     * - Ajoute la quantité passée à la valeur existante (mode additif).
     * - Choisit la première move line existante, sinon en crée une minimale.
     */
    private function updateMoveLinesQuantityDone(int $moveId, float $qty): void
    {
        // Lire les move lines existantes
        $mls = [];
        try {
            $mls = $this->rpc->callKW('stock.move.line','search_read', [[['move_id','=', $moveId]]], [
                'fields' => ['id','qty_done','quantity_done'],
                'limit'  => 2000
            ]);
        } catch (\Throwable) {
            $mls = [];
        }

        // Déterminer le champ accepté (préférence pour qty_done)
        $field = 'qty_done';
        $current = 0.0;
        if ($mls && isset($mls[0])) {
            $first = $mls[0];
            if (array_key_exists('quantity_done', $first) && !array_key_exists('qty_done', $first)) {
                $field = 'quantity_done';
                $current = (float)($first['quantity_done'] ?? 0.0);
            } else {
                $current = (float)($first['qty_done'] ?? 0.0);
            }
        }

        if ($mls) {
            $lineId = (int)($mls[0]['id'] ?? 0);
            if ($lineId > 0) {
                $newVal = $current + (float)$qty;
                if ($newVal < 0) { $newVal = 0.0; }
                $this->rpc->callKW('stock.move.line','write', [[$lineId], [$field => $newVal]]);
                return;
            }
        }

        // Créer une move line minimale (Odoo peut requérir des champs supplémentaires selon configuration)
        try {
            $this->rpc->callKW('stock.move.line','create', [['move_id' => $moveId, $field => (float)$qty]]);
        } catch (\Throwable) {
            // En dernier recours: on ne bloque pas, mais on laisse l’erreur silencieuse
        }
    }

    public function getSaleOrderState(int $saleOrderId): ?string
    {
        $r = $this->rpc->read('sale.order', [$saleOrderId], ['state']);
        return $r[0]['state'] ?? null;
    }

    /** Récupère un picking + ses moves, avec stocks produits (forecast/on hand) et picked actuel */
    public function getPickingWithMoves(int $pickingId): array
    {
        $p = $this->rpc->read('stock.picking', [$pickingId], ['id','name','origin','state','partner_id','scheduled_date']);
        if (!$p || empty($p[0])) {
            throw new \RuntimeException('Odoo: picking introuvable');
        }
        $picking = $p[0];

        $hasMoveQtyDone = $this->modelHasField('stock.move', 'quantity_done');
        $hasPickedFlag  = $this->modelHasField('stock.move', 'picked');
        $fields = ['id','product_id','product_uom','product_uom_qty','move_line_ids'];
        if ($hasMoveQtyDone) {
            $fields[] = 'quantity_done';
        }
        if ($hasPickedFlag) {
            $fields[] = 'picked';
        }

        $moves = $this->rpc->callKW('stock.move', 'search_read', [[['picking_id', '=', $pickingId]]], [
            'fields' => $fields,
            'limit'  => 2000
        ]);

        $pids = [];
        $allMlIds = [];
        foreach ($moves as $m) {
            $pid = is_array($m['product_id'] ?? null) ? ($m['product_id'][0] ?? null) : ($m['product_id'] ?? null);
            if ($pid) { $pids[(int)$pid] = true; }
            if (!empty($m['move_line_ids'])) {
                foreach ($m['move_line_ids'] as $mlId) { $allMlIds[] = $mlId; }
            }
        }

        $pickedFromLines = $allMlIds ? $this->sumMoveLineDoneByProduct($allMlIds) : [];

        $prodStocks = [];
        if ($pids) {
            $ids = array_keys($pids);

            // Lecture produits: ajoute barcode pour affichage et infos UoM/stock globaux
            $prods = $this->rpc->read('product.product', $ids, ['qty_available','virtual_available','default_code','barcode','name','uom_id']);
            foreach ($prods as $pr) {
                $prodStocks[(int)$pr['id']] = [
                    'qty_available'     => (float)($pr['qty_available'] ?? 0.0),
                    'virtual_available' => (float)($pr['virtual_available'] ?? 0.0),
                    'default_code'      => (string)($pr['default_code'] ?? ''),
                    'barcode'           => (string)($pr['barcode'] ?? ''),
                    'name'              => (string)($pr['name'] ?? ''),
                    'uom'               => is_array(($pr['uom_id'] ?? null)) ? ($pr['uom_id'][1] ?? '') : '',
                    // 'locations' sera ajouté après lecture des quants
                ];
            }

            // Lecture des quants par produit pour afficher le stock dispo par emplacement
            try {
                $domain = [['product_id', 'in', $ids], ['quantity', '>', 0]];
                $quants = $this->rpc->callKW('stock.quant', 'search_read', [$domain], [
                    'fields' => ['product_id','location_id','available_quantity','quantity'],
                    'limit'  => 5000
                ]);

                // 1) Agréger par produit et par emplacement + collecter les IDs d'emplacement
                $rawByPidLoc = [];
                $locIds = [];
                foreach ($quants as $q) {
                    $pid = is_array($q['product_id'] ?? null) ? ($q['product_id'][0] ?? null) : ($q['product_id'] ?? null);
                    if (!$pid) { continue; }
                    $locId = is_array($q['location_id'] ?? null) ? ($q['location_id'][0] ?? null) : ($q['location_id'] ?? null);
                    if (!$locId) { continue; }
                    $qty = isset($q['available_quantity']) ? (float)$q['available_quantity'] : (float)($q['quantity'] ?? 0.0);
                    if ($qty <= 0) { continue; }

                    $rawByPidLoc[(int)$pid][$locId] = ($rawByPidLoc[(int)$pid][$locId] ?? 0.0) + $qty;
                    $locIds[(int)$locId] = true;
                }

                // 2) Lire les infos d'emplacement (complete_name) pour déduire l'entrepôt (préfixe)
                $locInfo = [];
                if ($locIds) {
                    $locRows = $this->rpc->read('stock.location', array_keys($locIds), ['id','name','complete_name']);
                    foreach ($locRows as $lr) {
                        $lid = (int)($lr['id'] ?? 0);
                        if ($lid <= 0) { continue; }
                        $full = (string)($lr['complete_name'] ?? ($lr['name'] ?? ''));
                        $locInfo[$lid] = $full !== '' ? $full : ('Loc#' . $lid);
                    }
                }

                // 3) Construire la structure finale avec 'name' et 'wh' (warehouse)
                $byPidLoc = [];
                foreach ($rawByPidLoc as $pid => $locs) {
                    foreach ($locs as $lid => $qty) {
                        $full = $locInfo[(int)$lid] ?? ('Loc#' . (int)$lid);
                        $wh = $full;
                        $pos = strpos($full, '/');
                        if ($pos !== false) {
                            $wh = substr($full, 0, $pos);
                        }
                        $byPidLoc[(int)$pid][] = [
                            'name' => $full,
                            'wh'   => $wh,
                            'qty'  => $qty,
                        ];
                    }
                }

                // 4) Tri par quantité décroissante pour lisibilité, et attacher au produit
                foreach ($byPidLoc as $pid => $locs) {
                    usort($locs, static fn(array $a, array $b) => ($b['qty'] <=> $a['qty']));
                    if (!isset($prodStocks[(int)$pid])) {
                        $prodStocks[(int)$pid] = [];
                    }
                    $prodStocks[(int)$pid]['locations'] = array_values($locs);
                }
            } catch (\Throwable) {
                // silencieux: si indisponible, on continue sans détail par emplacement
            }
        }

        // Quantités initialement commandées (SO) via origin -> sale.order -> sale.order.line
        $orderedByPid = [];
        $origin = (string)($picking['origin'] ?? '');
        if ($origin !== '') {
            try {
                $soList = $this->rpc->callKW('sale.order', 'search_read', [[['name', '=', $origin]]], [
                    'fields' => ['id','name'], 'limit' => 1
                ]);
                if ($soList) {
                    $soId = (int)($soList[0]['id'] ?? 0);
                    if ($soId > 0) {
                        $sol = $this->rpc->callKW('sale.order.line','search_read', [[['order_id', '=', $soId], ['display_type','=',false]]], [
                            'fields' => ['product_id','product_uom_qty'], 'limit' => 2000
                        ]);
                        foreach ($sol as $l) {
                            $pid = is_array($l['product_id'] ?? null) ? ($l['product_id'][0] ?? null) : ($l['product_id'] ?? null);
                            if ($pid) {
                                $orderedByPid[(int)$pid] = ($orderedByPid[(int)$pid] ?? 0.0) + (float)($l['product_uom_qty'] ?? 0.0);
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // silencieux: pas bloquant si non trouvé
            }
        }

        $lines = [];
        foreach ($moves as $m) {
            $pid   = is_array($m['product_id'] ?? null) ? ($m['product_id'][0] ?? null) : ($m['product_id'] ?? null);
            $label = is_array($m['product_id'] ?? null) ? ($m['product_id'][1] ?? '') : '';
            $uom   = is_array($m['product_uom'] ?? null) ? ($m['product_uom'][1] ?? '') : '';

            $demanded = (float)($m['product_uom_qty'] ?? 0.0);
            $pickedQty = 0.0;
            if ($pid) {
                if ($hasMoveQtyDone && isset($m['quantity_done'])) {
                    $pickedQty = (float)$m['quantity_done'];
                } else {
                    $pickedQty = (float)($pickedFromLines[(int)$pid] ?? 0.0);
                }
            }

            $stocks  = $pid && isset($prodStocks[(int)$pid]) ? $prodStocks[(int)$pid] : ['qty_available'=>0.0,'virtual_available'=>0.0,'default_code'=>'','barcode'=>'','name'=>'','uom'=>'','locations'=>[]];
            $ordered = $pid && isset($orderedByPid[(int)$pid]) ? (float)$orderedByPid[(int)$pid] : null;

            // picked booléen venant d’Odoo si disponible, sinon fallback indicatif
            $pickedFlag = false;
            if (isset($m['picked'])) {
                $pickedFlag = (bool)$m['picked'];
            } else {
                $pickedFlag = ($pickedQty >= $demanded && $demanded > 0);
            }

            $lines[] = [
                'product_id'       => (int)$pid,
                'product_label'    => $label !== '' ? $label : ($stocks['default_code'] !== '' ? '['.$stocks['default_code'].'] '.$stocks['name'] : $stocks['name']),
                'product_uom'      => $uom !== '' ? $uom : $stocks['uom'],
                'product_uom_qty'  => $demanded,         // demandé sur le mouvement
                'ordered_qty'      => $ordered,          // demandé initialement (SO)
                'picked'           => $pickedFlag,       // bool Odoo si présent
                'picked_qty'       => $pickedQty,        // quantité déjà faite
                'forecast'         => (float)$stocks['virtual_available'],
                'on_hand'          => (float)$stocks['qty_available'],
                'default_code'     => (string)$stocks['default_code'],
                'barcode'          => (string)$stocks['barcode'],
                'locations'        => is_array($stocks['locations'] ?? null) ? $stocks['locations'] : [],
            ];
        }

        return ['picking' => $picking, 'lines' => $lines];
    }

    /** Met à jour les quantités “picked” sans valider le picking */
    public function setPickedQuantities(int $pickingId, array $qtyByProductId): void
    {
        // Lecture minimale des moves
        $moves = $this->rpc->callKW('stock.move','search_read', [[['picking_id','=', $pickingId]]], [
            'fields' => ['id','product_id'],
            'limit'  => 2000
        ]);

        $byProduct = [];
        foreach ($moves as $m) {
            $pid = is_array($m['product_id'] ?? null) ? ($m['product_id'][0] ?? null) : ($m['product_id'] ?? null);
            if ($pid && !isset($byProduct[(int)$pid])) {
                $byProduct[(int)$pid] = $m;
            }
        }

        foreach ($qtyByProductId as $pid => $qty) {
            $pid = (int)$pid;
            $qty = (float)$qty;
            if ($pid <= 0 || !isset($byProduct[$pid])) { continue; }
            $moveId = (int)$byProduct[$pid]['id'];

            try {
                $this->rpc->callKW('stock.move','write', [[$moveId], ['quantity_done' => $qty]]);
            } catch (\Throwable $e) {
                $this->updateMoveLinesQuantityDone($moveId, $qty);
            }
        }
    }

    /** Fixe les quantités “picked” par product_id et valide le picking (wizard inclus) */
    public function setPickedAndValidatePicking(int $pickingId, array $qtyByProductId): void
    {
        // Moves du picking (lecture minimale)
        $moves = $this->rpc->callKW('stock.move', 'search_read', [[['picking_id', '=', $pickingId]]], [
            'fields' => ['id','product_id'],
            'limit'  => 2000
        ]);

        // Index par product_id
        $byProduct = [];
        foreach ($moves as $m) {
            $pid = is_array($m['product_id'] ?? null) ? ($m['product_id'][0] ?? null) : ($m['product_id'] ?? null);
            if ($pid && !isset($byProduct[(int)$pid])) {
                $byProduct[(int)$pid] = $m;
            }
        }

        // Appliquer les quantités avec repli sur move lines si besoin
        foreach ($qtyByProductId as $pid => $qty) {
            $pid = (int)$pid;
            $qty = (float)$qty;
            if ($pid <= 0 || !isset($byProduct[$pid])) { continue; }
            $moveId = (int)$byProduct[$pid]['id'];

            try {
                $this->rpc->callKW('stock.move','write', [[$moveId], ['quantity_done' => $qty]]);
            } catch (\Throwable $e) {
                $this->updateMoveLinesQuantityDone($moveId, $qty);
            }
        }

        // Valider
        $res = $this->rpc->callKW('stock.picking', 'button_validate', [[$pickingId]]);

        // Immediate transfer wizard
        if (is_array($res) && ($res['res_model'] ?? '') === 'stock.immediate.transfer') {
            if (!empty($res['res_id'])) {
                $this->rpc->callKW('stock.immediate.transfer','process', [[$res['res_id']]]);
            } else {
                $wizId = $this->rpc->callKW('stock.immediate.transfer','create', [['pick_ids' => [$pickingId]]]);
                $this->rpc->callKW('stock.immediate.transfer','process', [[$wizId]]);
            }
        }

        // Backorder wizard → accepter pour terminer
        if (is_array($res) && ($res['res_model'] ?? '') === 'stock.backorder.confirmation') {
            if (!empty($res['res_id'])) {
                $this->rpc->callKW('stock.backorder.confirmation','process', [[$res['res_id']]]);
            } else {
                $ctx = ['active_model' => 'stock.picking', 'active_ids' => [$pickingId], 'active_id' => $pickingId];
                $wizId = $this->rpc->callKW('stock.backorder.confirmation','create', [[]], ['context' => $ctx]);
                $this->rpc->callKW('stock.backorder.confirmation','process', [[$wizId]], ['context' => $ctx]);
            }
        }
    }

    /**
     * Lit product.product pour une liste d'IDs et retourne un map id => infos (barcode, default_code, name, uom, stocks).
     * Enrichit avec les emplacements de stock (stock.quant → stock.location).
     */
    public function getProductsInfo(array $ids): array
    {
        if (empty($ids)) { return []; }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // Produits
        $rows = $this->rpc->read('product.product', $ids, ['id','default_code','barcode','name','uom_id','qty_available','virtual_available']);
        $out = [];
        foreach ($rows as $r) {
            $pid = (int)($r['id'] ?? 0);
            if ($pid <= 0) { continue; }
            $out[$pid] = [
                'default_code'      => (string)($r['default_code'] ?? ''),
                'barcode'           => (string)($r['barcode'] ?? ''),
                'name'              => (string)($r['name'] ?? ''),
                'uom'               => is_array($r['uom_id'] ?? null) ? ($r['uom_id'][1] ?? '') : '',
                'qty_available'     => (float)($r['qty_available'] ?? 0.0),
                'virtual_available' => (float)($r['virtual_available'] ?? 0.0),
                'locations'         => [], // rempli ci-dessous
            ];
        }

        // Quants par produit → emplacements
        try {
            $domain = [['product_id', 'in', $ids], ['quantity', '>', 0]];
            $quants = $this->rpc->callKW('stock.quant', 'search_read', [$domain], [
                'fields' => ['product_id','location_id','available_quantity','quantity'],
                'limit'  => 5000
            ]);

            // Agrégats et collect des location_ids
            $rawByPidLoc = [];
            $locIds = [];
            foreach ($quants as $q) {
                $pid = is_array($q['product_id'] ?? null) ? ($q['product_id'][0] ?? null) : ($q['product_id'] ?? null);
                if (!$pid) { continue; }
                $locId = is_array($q['location_id'] ?? null) ? ($q['location_id'][0] ?? null) : ($q['location_id'] ?? null);
                if (!$locId) { continue; }
                $qty = isset($q['available_quantity']) ? (float)$q['available_quantity'] : (float)($q['quantity'] ?? 0.0);
                if ($qty <= 0) { continue; }

                $rawByPidLoc[(int)$pid][$locId] = ($rawByPidLoc[(int)$pid][$locId] ?? 0.0) + $qty;
                $locIds[(int)$locId] = true;
            }

            // Lire stock.location pour récupérer le complete_name et en déduire l'entrepôt
            $locInfo = [];
            if ($locIds) {
                $locRows = $this->rpc->read('stock.location', array_keys($locIds), ['id','name','complete_name']);
                foreach ($locRows as $lr) {
                    $lid = (int)($lr['id'] ?? 0);
                    if ($lid <= 0) { continue; }
                    $full = (string)($lr['complete_name'] ?? ($lr['name'] ?? ''));
                    $locInfo[$lid] = $full !== '' ? $full : ('Loc#' . $lid);
                }
            }

            // Construire la liste triée d'emplacements {wh, name, qty}
            foreach ($rawByPidLoc as $pid => $locs) {
                $list = [];
                foreach ($locs as $lid => $qty) {
                    $full = $locInfo[(int)$lid] ?? ('Loc#' . (int)$lid);
                    $wh = $full;
                    $pos = strpos($full, '/');
                    if ($pos !== false) {
                        $wh = substr($full, 0, $pos);
                    }
                    $list[] = [
                        'wh'  => $wh,
                        'name'=> $full,
                        'qty' => $qty,
                    ];
                }
                usort($list, static fn(array $a, array $b) => ($b['qty'] <=> $a['qty']));
                if (!isset($out[(int)$pid])) {
                    $out[(int)$pid] = [];
                }
                $out[(int)$pid]['locations'] = $list;
            }
        } catch (\Throwable) {
            // silencieux: si indispo, on laisse locations vide
        }

        return $out;
    }

    /**
     * Force la création/validation d'un reliquat (stock.backorder.confirmation) pour tous les pickings
     * ouverts (non done/cancel) d'une vente (par id numérique ou par nom).
     */
    public function forceBackorderForOrder(int|string $idOrName): void
    {
        $data = $this->fetchSaleOrder($idOrName);
        $pickings = (array)($data['pickings'] ?? []);
        if (!$pickings) { return; }

        foreach ($pickings as $p) {
            $pid = (int)($p['id'] ?? 0);
            $state = (string)($p['state'] ?? '');
            if ($pid > 0 && !in_array($state, ['done','cancel'], true)) {
                $ctx = ['active_model' => 'stock.picking', 'active_ids' => [$pid], 'active_id' => $pid];
                try {
                    $wizId = $this->rpc->callKW('stock.backorder.confirmation','create', [[]], ['context' => $ctx]);
                    $this->rpc->callKW('stock.backorder.confirmation','process', [[$wizId]], ['context' => $ctx]);
                } catch (\Throwable) {
                    // on continue avec les suivants
                }
            }
        }
    }
}


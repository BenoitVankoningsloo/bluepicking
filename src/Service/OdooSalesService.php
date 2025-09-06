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
                'amount_total','currency_id',
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
    public function pushPreparedAndValidate(Connection $db, int $localOrderId): void
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
        $fields = ['id','product_id','product_uom','product_uom_qty','move_line_ids'];
        if ($this->modelHasField('stock.move','quantity_done')) {
            $fields[] = 'quantity_done';
        }
        $moves = $this->rpc->callKW('stock.move', 'search_read', [[['picking_id', '=', $pickingId]]], [
            'fields' => $fields,
            'limit'  => 2000
        ]);

        // Index par product_id
        $byProduct = [];
        foreach ($moves as $m) {
            $pid = $this->idFromMany2One($m['product_id'] ?? null);
            if ($pid && !isset($byProduct[$pid])) {
                $byProduct[$pid] = $m;
            }
        }

        // 6) Appliquer les quantités sur **stock.move**
        $hasMoveQtyDone = $this->modelHasField('stock.move','quantity_done');
        foreach ($target as $pid => $qty) {
            if (!isset($byProduct[$pid])) { continue; }
            $m = $byProduct[$pid];
            $moveId = (int)$m['id'];

            if ($hasMoveQtyDone) {
                // a) Champ moderne : écrire quantity_done = qty préparée
                $this->rpc->callKW('stock.move','write', [[$moveId], ['quantity_done' => (float)$qty]]);
            } else {
                // b) Fallback legacy : écraser product_uom_qty par la quantité préparée
                // (certains schémas anciens valident sur la quantité de move)
                $this->rpc->callKW('stock.move','write', [[$moveId], ['product_uom_qty' => (float)$qty]]);
            }
        }

        // 7) Valider le picking
        $res = $this->rpc->callKW('stock.picking', 'button_validate', [[$pickingId]]);

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

        // b) Backorder wizard → on **accepte** le backorder pour finir en done
        if (is_array($res) && ($res['res_model'] ?? '') === 'stock.backorder.confirmation') {
            if (!empty($res['res_id'])) {
                $this->rpc->callKW('stock.backorder.confirmation','process', [[$res['res_id']]]);
            } else {
                // Recréation au besoin
                $ctx = ['active_model' => 'stock.picking', 'active_ids' => [$pickingId], 'active_id' => $pickingId];
                $wizId = $this->rpc->callKW('stock.backorder.confirmation','create', [[]], ['context' => $ctx]);
                $this->rpc->callKW('stock.backorder.confirmation','process', [[$wizId]], ['context' => $ctx]);
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

    public function getSaleOrderState(int $saleOrderId): ?string
    {
    $r = $this->rpc->read('sale.order', [$saleOrderId], ['state']);
    return $r[0]['state'] ?? null; 
    }
}


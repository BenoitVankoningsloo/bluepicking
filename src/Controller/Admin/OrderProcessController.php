<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSalesService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class OrderProcessController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @throws Exception
     */
    #[Route('/admin/orders/{id<\\d+>}/process', name: 'admin_orders_process', methods: ['GET','POST'])]
    public function __invoke(int $id, Request $req, OdooSalesService $odooSvc): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $o = $this->db->fetchAssociative('SELECT * FROM sales_orders WHERE id = ?', [$id]);
        if (!$o) { throw $this->createNotFoundException('Commande introuvable'); }

        $meta   = $this->db->fetchAssociative('SELECT * FROM sales_order_meta WHERE order_id = ?', [$id]) ?: [];
        $locked = !empty($meta['picking_validated_at']);

        // Récupère l'état Odoo courant (sale.order.state) si la commande est liée
        $odooState = null;
        if (!empty($o['odoo_sale_order_id'])) {
            try { $odooState = $odooSvc->getSaleOrderState((int)$o['odoo_sale_order_id']); } catch (Throwable) {}
        }

        if ($req->isMethod('POST') && !$locked) {
            $p = $req->request;

            // 1) Metadonnées de préparation
            $parcels = max(1, (int)$p->get('parcels_count', 1));

            // Logique portable: UPDATE d'abord, INSERT si aucune ligne affectée
            $preparedBy = (string)$p->get('prepared_by', (string)$this->getUser()?->getUserIdentifier());
            $affected = $this->db->executeStatement(
                'UPDATE sales_order_meta
                   SET parcels_count = ?,
                       package_ref   = ?,
                       prepared_by   = ?,
                       ship_name     = ?,
                       ship_phone    = ?,
                       ship_address1 = ?,
                       ship_address2 = ?,
                       ship_postcode = ?,
                       ship_city     = ?,
                       ship_country  = ?
                 WHERE order_id = ?',
                [
                    $parcels,
                    (string)$p->get('package_ref'),
                    $preparedBy,
                    (string)$p->get('ship_name'),
                    (string)$p->get('ship_phone'),
                    (string)$p->get('ship_address1'),
                    (string)$p->get('ship_address2'),
                    (string)$p->get('ship_postcode'),
                    (string)$p->get('ship_city'),
                    (string)$p->get('ship_country'),
                    $id,
                ]
            );
            if ($affected === 0) {
                $this->db->executeStatement(
                    'INSERT INTO sales_order_meta
                        (order_id, parcels_count, package_ref, prepared_by, ship_name, ship_phone, ship_address1, ship_address2, ship_postcode, ship_city, ship_country)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id,
                        $parcels,
                        (string)$p->get('package_ref'),
                        $preparedBy,
                        (string)$p->get('ship_name'),
                        (string)$p->get('ship_phone'),
                        (string)$p->get('ship_address1'),
                        (string)$p->get('ship_address2'),
                        (string)$p->get('ship_postcode'),
                        (string)$p->get('ship_city'),
                        (string)$p->get('ship_country'),
                    ]
                );
            }

            // 2) Transport / tracking (timestamp portable)
            $this->db->executeStatement(
                'UPDATE sales_orders SET shipping_carrier = ?, shipping_service = ?, tracking_number = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [
                    (string)$p->get('shipping_carrier'),
                    (string)$p->get('shipping_service'),
                    (string)$p->get('tracking_number'),
                    $id
                ]
            );

            $this->addFlash('success', 'Données enregistrées.');
            return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
        }

        // Lignes produits
        $lines = $this->db->fetchAllAssociative('SELECT * FROM sales_order_lines WHERE order_id = ? ORDER BY id ASC', [$id]);

        // Fallback adresse depuis payload_json (si meta vide)
        $partner = null;
        if (!empty($o['payload_json'])) {
            $payload = json_decode((string)$o['payload_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) { $partner = $payload['partner'] ?? null; }
        }
        $ship = [
            'name'     => $meta['ship_name']     ?? ($partner['name'] ?? ($o['customer_name'] ?? '')),
            'phone'    => $meta['ship_phone']    ?? ($partner['phone'] ?? ''),
            'address1' => $meta['ship_address1'] ?? ($partner['street'] ?? ''),
            'address2' => $meta['ship_address2'] ?? ($partner['street2'] ?? ''),
            'postcode' => $meta['ship_postcode'] ?? ($partner['zip'] ?? ''),
            'city'     => $meta['ship_city']     ?? ($partner['city'] ?? ''),
            'country'  => $meta['ship_country']  ?? ($partner['country_code'] ?? 'BE'),
        ];

        // Dropdown préparateur : lit les rôles JSON et filtre en PHP sur ROLE_PREPARATEUR
        $users = [];
        $rows = [];
        try {
            // Table standard
            $rows = $this->db->fetchAllAssociative('SELECT name, email, roles FROM users');
        } catch (Throwable) {
            try {
                // Variante éventuelle (nom de table différent)
                $rows = $this->db->fetchAllAssociative('SELECT name, email, roles FROM "user"');
            } catch (Throwable) {
                $rows = [];
            }
        }
        if ($rows) {
            $tmp = [];
            foreach ($rows as $r) {
                $email = trim((string)($r['email'] ?? ''));
                if ($email === '') { continue; }
                $name  = trim((string)($r['name'] ?? ''));
                $rolesRaw = $r['roles'] ?? '[]';
                $rolesArr = is_string($rolesRaw) ? json_decode($rolesRaw, true) : (is_array($rolesRaw) ? $rolesRaw : []);
                if (!is_array($rolesArr)) { $rolesArr = []; }

                if (in_array('ROLE_PREPARATEUR', $rolesArr, true)) {
                    $label = $name !== '' ? $name : $email;
                    $tmp[] = ['label' => $label, 'value' => $email];
                }
            }
            // Tri alpha sur label
            usort($tmp, static function (array $a, array $b): int {
                return strcasecmp($a['label'] ?? '', $b['label'] ?? '');
            });
            $users = $tmp;
        }
        if (!$users) {
            $current = (string) ($this->getUser()?->getUserIdentifier() ?? 'admin');
            $users = [['label' => $current, 'value' => $current]];
        }

        // Pickings liés (via origin = odoo_name ou external_order_id)
        $pickings = [];
        try {
            $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne();
            $originRef = (string)($o['odoo_name'] ?? '');
            if ($originRef === '' && !empty($o['external_order_id'])) {
                $originRef = (string)$o['external_order_id'];
            }
            if ($originRef !== '') {
                $pickings = $this->db->fetchAllAssociative(
                    'SELECT odoo_id, name, state FROM odoo_pickings WHERE origin = ? ORDER BY id ASC',
                    [$originRef]
                );
            }
        } catch (\Throwable) {
            $pickings = [];
        }

                    // Récupère le picking lié (via origin) et ses lignes pour affichage one-page
                    $pickingData = null;
                    $pickingLinesByPid = [];
                    try {
                        $originRef = (string)($o['odoo_name'] ?? ($o['external_order_id'] ?? ''));
                        if ($originRef !== '') {
                            // Cherche un picking par origin, privilégie un état non done/cancel
                            $cands = $odooSvc->listPickings([['origin', '=', $originRef]], 10, 0, ['id','name','state','scheduled_date','origin']);
                            if ($cands) {
                                $chosenId = null;
                                foreach ($cands as $p) {
                                    $st = (string)($p['state'] ?? '');
                                    if (!in_array($st, ['done','cancel'], true)) { $chosenId = (int)$p['id']; break; }
                                }
                                if ($chosenId === null) { $chosenId = (int)$cands[0]['id']; }
                                if ($chosenId > 0) {
                                    $pickingData = $odooSvc->getPickingWithMoves($chosenId);
                                    foreach (($pickingData['lines'] ?? []) as $pl) {
                                        $pid = (int)($pl['product_id'] ?? 0);
                                        if ($pid) { $pickingLinesByPid[$pid] = $pl; }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // silencieux: on continue sans afficher la section picking
                        $pickingData = null;
                        $pickingLinesByPid = [];
                    }

                        // Mapping produit -> liste de pickings (id, name, state) pour affichage par ligne produit
                        $productPickings = [];
                        try {
                            if (is_array($pickings) && $pickings) {
                                // Limite pour éviter des dizaines d'appels en cascade si beaucoup de BL
                                $maxToInspect = 6;
                                $inspected = 0;

                                foreach ($pickings as $pk) {
                                    if ($inspected >= $maxToInspect) { break; }
                                    $rawId = $pk['odoo_id'] ?? null;
                                    if (!is_numeric($rawId)) { continue; }
                                    $odooPickId = (int)$rawId;
                                    if ($odooPickId <= 0) { continue; }

                                    // Lecture Odoo pour récupérer les lignes de ce picking
                                    $pd = $odooSvc->getPickingWithMoves($odooPickId);
                                    $pname  = (string)($pd['picking']['name']  ?? '');
                                    $pstate = (string)($pd['picking']['state'] ?? '');
                                    $linesPd = $pd['lines'] ?? [];

                                    if (is_array($linesPd) && $linesPd) {
                                        foreach ($linesPd as $pl) {
                                            $prodIdRaw = $pl['product_id'] ?? null;
                                            $prodId = is_numeric($prodIdRaw) ? (int)$prodIdRaw : 0;
                                            if ($prodId <= 0) { continue; }

                                            // dédoublonnage par id de picking
                                            $already = isset($productPickings[$prodId]) ? array_column($productPickings[$prodId], 'id') : [];
                                            if (!in_array($odooPickId, $already, true)) {
                                                $productPickings[$prodId][] = [
                                                    'id'    => $odooPickId,
                                                    'name'  => $pname !== '' ? $pname : (string)$odooPickId,
                                                    'state' => $pstate,
                                                ];
                                            }
                                        }
                                    }

                                    $inspected++;
                                }
                            }
                        } catch (\Throwable) {
                            // silencieux, on affiche juste sans mapping si erreur
                            $productPickings = [];
                        }

                            // Ajout du picking "live" choisi (pickingData) au mapping produit -> pickings
                            if (is_array($pickingData) && !empty($pickingData['picking']['id'])) {
                                $odooPickId = (int)($pickingData['picking']['id'] ?? 0);
                                $pname  = (string)($pickingData['picking']['name']  ?? ($odooPickId ?: ''));
                                $pstate = (string)($pickingData['picking']['state'] ?? '');
                                $linesPd = $pickingData['lines'] ?? [];
                                if ($odooPickId > 0 && is_array($linesPd)) {
                                    foreach ($linesPd as $pl) {
                                        $prodId = (int)($pl['product_id'] ?? 0);
                                        if ($prodId <= 0) { continue; }
                                        $already = isset($productPickings[$prodId]) ? array_column($productPickings[$prodId], 'id') : [];
                                        if (!in_array($odooPickId, $already, true)) {
                                            $productPickings[$prodId][] = [
                                                'id'    => $odooPickId,
                                                'name'  => $pname !== '' ? $pname : (string)$odooPickId,
                                                'state' => $pstate,
                                            ];
                                        }
                                    }
                                }
                            }

                        // Infos produits (barcode, référence interne...) depuis Odoo pour garantir l'affichage même sans picking enrichi
                        $productsInfoByPid = [];
                        try {
                            $pidSet = [];
                            foreach ($lines as $ln) {
                                $pid = (int)($ln['odoo_product_id'] ?? 0);
                                if ($pid > 0) { $pidSet[$pid] = true; }
                            }
                            if ($pidSet) {
                                $productsInfoByPid = $odooSvc->getProductsInfo(array_keys($pidSet));
                            }
                        } catch (\Throwable) {
                            $productsInfoByPid = [];
                        }

                        // Quantités du Sales Order en live (fallback si aucun picking)
                        $soLinesByPid = [];
                        try {
                            $idOrName = null;
                            if (!empty($o['odoo_name'])) {
                                $idOrName = (string)$o['odoo_name'];
                            } elseif (!empty($o['odoo_sale_order_id'])) {
                                $idOrName = (int)$o['odoo_sale_order_id'];
                            }
                            if ($idOrName !== null && $idOrName !== '') {
                                $so = $odooSvc->fetchSaleOrder($idOrName);
                                foreach (($so['lines'] ?? []) as $sl) {
                                    $pid = is_array($sl['product_id'] ?? null) ? ($sl['product_id'][0] ?? null) : ($sl['product_id'] ?? null);
                                    if (!$pid) { continue; }
                                    $qty = (float)($sl['product_uom_qty'] ?? 0.0);
                                    $uom = is_array($sl['product_uom'] ?? null) ? ($sl['product_uom'][1] ?? '') : ($sl['product_uom'] ?? '');
                                    $soLinesByPid[(int)$pid] = [
                                        'ordered_qty' => $qty,
                                        'product_uom' => $uom,
                                    ];
                                }
                            }
                        } catch (\Throwable) {
                            $soLinesByPid = [];
                        }

                        // Récupérer TOUS les pickings liés (via SO.picking_ids en priorité), chacun avec ses lignes
                        $allPickings = [];
                        try {
                            // 1) Via sale.order (picking_ids)
                            $ref = null;
                            if (!empty($o['odoo_sale_order_id'])) {
                                $ref = (int)$o['odoo_sale_order_id'];
                            } elseif (!empty($o['odoo_name'])) {
                                $ref = (string)$o['odoo_name'];
                            } elseif (!empty($o['external_order_id'])) {
                                $ref = (string)$o['external_order_id'];
                            }

                            $pickedIds = [];
                            if ($ref !== null && $ref !== '') {
                                $soData = $odooSvc->fetchSaleOrder($ref); // ['so','partner','lines','pickings','moveSum']
                                $soPickings = (array)($soData['pickings'] ?? []);
                                foreach ($soPickings as $pk) {
                                    $pid = (int)($pk['id'] ?? 0);
                                    if ($pid > 0) { $pickedIds[$pid] = true; }
                                }
                                foreach (array_keys($pickedIds) as $pid) {
                                    try {
                                        $pd = $odooSvc->getPickingWithMoves($pid);
                                        if ($pd && !empty($pd['lines'])) {
                                            $allPickings[] = $pd;
                                        }
                                    } catch (\Throwable) { /* ignore */ }
                                }
                            }

                            // 2) Fallback par origin si rien
                            if (!$allPickings) {
                                $originRef = (string)($o['odoo_name'] ?? ($o['external_order_id'] ?? ''));
                                if ($originRef !== '') {
                                    $all = $odooSvc->listPickings([['origin', '=', $originRef]], 50, 0, ['id','name','state','scheduled_date','origin']);
                                    foreach ($all as $rowPk) {
                                        $pid = (int)($rowPk['id'] ?? 0);
                                        if ($pid <= 0) { continue; }
                                        try {
                                            $pd = $odooSvc->getPickingWithMoves($pid);
                                            if ($pd && !empty($pd['lines'])) {
                                                $allPickings[] = $pd;
                                            }
                                        } catch (\Throwable) { /* ignore */ }
                                    }
                                }
                            }
                        } catch (\Throwable) {
                            $allPickings = [];
                        }

                        // Récupérer les pickings de reliquat (autres BL en attente) et leurs lignes
                        $backorderPickings = [];
                        try {
                            $originRef = (string)($o['odoo_name'] ?? ($o['external_order_id'] ?? ''));
                            if ($originRef !== '') {
                                $all = $odooSvc->listPickings([['origin', '=', $originRef]], 20, 0, ['id','name','state','scheduled_date','origin']);
                                $chosenId = is_array($pickingData) && !empty($pickingData['picking']['id']) ? (int)$pickingData['picking']['id'] : 0;
                                foreach ($all as $rowPk) {
                                    $pid = (int)($rowPk['id'] ?? 0);
                                    $state = (string)($rowPk['state'] ?? '');
                                    if ($pid <= 0 || $pid === $chosenId) { continue; }
                                    // On ne liste que les BL non terminés/annulés
                                    if (in_array($state, ['done','cancel'], true)) { continue; }
                                    $pd = $odooSvc->getPickingWithMoves($pid);
                                    if ($pd && !empty($pd['lines'])) {
                                        $backorderPickings[] = $pd; // contient ['picking'=>..., 'lines'=>...]
                                    }
                                }
                            }
                        } catch (\Throwable) {
                            $backorderPickings = [];
                        }

                        // Filtre des lignes pour le BL courant: on ne garde que les produits présents dans le picking sélectionné
                        $linesCurrent = $lines;
                        if (!empty($pickingLinesByPid)) {
                            $linesCurrent = array_values(array_filter($lines, static function (array $ln) use ($pickingLinesByPid): bool {
                                $pid = (int)($ln['odoo_product_id'] ?? 0);
                                return $pid > 0 && isset($pickingLinesByPid[$pid]);
                            }));
                        }

                        // Lignes déjà préparées (prepared_qty > 0) pour le BL courant
                        $preparedLinesCurrent = [];
                        if (!empty($pickingLinesByPid)) {
                            foreach ($lines as $ln) {
                                $pid = (int)($ln['odoo_product_id'] ?? 0);
                                $pq  = $ln['prepared_qty'] ?? null;
                                if ($pid > 0 && isset($pickingLinesByPid[$pid]) && $pq !== null && (float)$pq > 0) {
                                    $preparedLinesCurrent[] = $ln;
                                }
                            }
                        }

                        // Mapping local par product_id (pour lier les inputs prepared[] aux IDs locaux)
                        $localLinesByPid = [];
                        foreach ($lines as $ln) {
                            $pid = (int)($ln['odoo_product_id'] ?? 0);
                            if ($pid > 0 && !isset($localLinesByPid[$pid])) {
                                $localLinesByPid[$pid] = $ln;
                            }
                        }

                        // ===== Navigation précédente/suivante basée sur la liste filtrée /admin/orders =====
                        $listQuery = $req->query->all();

                        // Filtres identiques à la liste
                        $q            = trim((string) ($listQuery['q'] ?? ''));
                        $status       = trim((string) ($listQuery['status'] ?? ''));
                        $source       = trim((string) ($listQuery['source'] ?? ''));
                        $from         = trim((string) ($listQuery['placed_from'] ?? ''));
                        $to           = trim((string) ($listQuery['placed_to'] ?? ''));
                        $preparedBy   = trim((string) ($listQuery['prepared_by'] ?? ''));
                        $carrier      = trim((string) ($listQuery['carrier'] ?? ''));
                        $tracking     = trim((string) ($listQuery['tracking'] ?? ''));
                        $deliveryState= trim((string) ($listQuery['delivery_state'] ?? ''));

                        $sort = (string) ($listQuery['sort'] ?? 'placed_at');
                        $dir  = strtolower((string) ($listQuery['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

                        $sortable = [
                            'external_order_id' => 'o.external_order_id',
                            'status'            => 'o.status',
                            'customer_name'     => 'o.customer_name',
                            'total_amount'      => 'o.total_amount',
                            'item_count'        => 'o.item_count',
                            'source'            => 'o.source',
                            'shipping_carrier'  => 'o.shipping_carrier',
                            'tracking_number'   => 'o.tracking_number',
                            'prepared_by'       => 'm.prepared_by',
                            'placed_at'         => 'o.placed_at',
                            'updated_at'        => 'o.updated_at',
                            'delivery_status'   => 'o.delivery_status',
                        ];
                        // Si delivery_status absent -> fallback tri
                        try { $this->db->executeQuery('SELECT delivery_status FROM sales_orders LIMIT 1')->fetchOne(); }
                        catch (\Throwable) {
                            unset($sortable['delivery_status']);
                            if ($sort === 'delivery_status') { $sort = 'placed_at'; }
                        }
                        $orderBy = $sortable[$sort] ?? 'o.placed_at';

                        $navQb = $this->db->createQueryBuilder()->from('sales_orders', 'o');
                        $navQb->leftJoin('o', 'sales_order_meta', 'm', 'm.order_id = o.id');

                        if ($q !== '') {
                            $navQb->andWhere('(o.external_order_id LIKE :q OR o.customer_name LIKE :q OR o.customer_email LIKE :q OR o.tracking_number LIKE :q)')
                                  ->setParameter('q', '%' . $q . '%');
                        }
                        if ($status !== '')   { $navQb->andWhere('o.status = :status')->setParameter('status', $status); }
                        if ($source !== '')   { $navQb->andWhere('o.source = :source')->setParameter('source', $source); }
                        if ($from !== '')     { $navQb->andWhere('o.placed_at >= :from')->setParameter('from', $from . ' 00:00:00'); }
                        if ($to !== '')       { $navQb->andWhere('o.placed_at <= :to')->setParameter('to', $to . ' 23:59:59'); }
                        if ($preparedBy !== '') { $navQb->andWhere('LOWER(m.prepared_by) = LOWER(:prep)')->setParameter('prep', $preparedBy); }
                        if ($carrier !== '')    { $navQb->andWhere('o.shipping_carrier = :carrier')->setParameter('carrier', $carrier); }
                        if ($tracking !== '')   { $navQb->andWhere('o.tracking_number LIKE :trk')->setParameter('trk', '%' . $tracking . '%'); }

                        // On sélectionne toutes les candidates ordonnées
                        $navQb->select('o.id', 'o.odoo_name', 'o.external_order_id', 'o.payload_json')
                              ->orderBy($orderBy, $dir);
                        $rowsNav = $this->db->fetchAllAssociative($navQb->getSQL(), $navQb->getParameters(), $navQb->getParameterTypes());

                        // Appliquer le filtre "état préparation" (livraison) de la même façon que la liste:
                        // priorité payload_json, sinon fallback via odoo_pickings
                        $rankPrep = ['assigned'=>40, 'confirmed'=>30, 'waiting'=>20, 'done'=>10, 'cancel'=>0, 'draft'=>5];
                        $bestById = [];
                        $needOrigins = [];
                        foreach ($rowsNav as $rowN) {
                            $oid = (int)($rowN['id'] ?? 0);
                            $best = null;
                            $payload = (string)($rowN['payload_json'] ?? '');
                            if ($payload !== '') {
                                try {
                                    $data = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                                    $pickings = \is_array($data['pickings'] ?? null) ? $data['pickings'] : [];
                                    foreach ($pickings as $p) {
                                        $st = \strtolower((string)($p['state'] ?? ''));
                                        if ($st === '' || !isset($rankPrep[$st])) { continue; }
                                        if ($best === null || $rankPrep[$st] > $rankPrep[$best]) { $best = $st; }
                                    }
                                } catch (\Throwable) {}
                            }
                            if ($best !== null) {
                                $bestById[$oid] = $best;
                            } else {
                                $origin = \trim((string)($rowN['odoo_name'] ?? ''));
                                if ($origin === '') { $origin = \trim((string)($rowN['external_order_id'] ?? '')); }
                                if ($origin !== '') { $needOrigins[$origin] = true; }
                            }
                        }
                        if ($needOrigins) {
                            $ok = true;
                            try { $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne(); }
                            catch (\Throwable) { $ok = false; }
                            if ($ok) {
                                $keys = \array_keys($needOrigins);
                                $ph   = \implode(',', \array_fill(0, \count($keys), '?'));
                                $rowsPk = $this->db->fetchAllAssociative('SELECT origin, state FROM odoo_pickings WHERE origin IN ('.$ph.')', $keys);
                                $bestByOrigin = [];
                                foreach ($rowsPk as $rp) {
                                    $oName = (string)($rp['origin'] ?? '');
                                    $st    = \strtolower((string)($rp['state'] ?? ''));
                                    if ($oName === '' || !isset($rankPrep[$st])) { continue; }
                                    $cur = $bestByOrigin[$oName] ?? null;
                                    if ($cur === null || $rankPrep[$st] > $rankPrep[$cur]) { $bestByOrigin[$oName] = $st; }
                                }
                                foreach ($rowsNav as $rowN) {
                                    $oid = (int)($rowN['id'] ?? 0);
                                    if (isset($bestById[$oid])) { continue; }
                                    $origin = \trim((string)($rowN['odoo_name'] ?? ''));
                                    if ($origin === '') { $origin = \trim((string)($rowN['external_order_id'] ?? '')); }
                                    if ($origin !== '' && isset($bestByOrigin[$origin])) {
                                        $bestById[$oid] = $bestByOrigin[$origin];
                                    }
                                }
                            }
                        }

                        // Appliquer le filtre demandé
                        $target = \strtolower($deliveryState);
                        $targets = $target === '' ? [] : (($target === 'waiting') ? ['waiting','confirmed'] : [$target]);

                        $orderedIds = [];
                        foreach ($rowsNav as $rowN) {
                            $oid = (int)($rowN['id'] ?? 0);
                            if ($deliveryState === '') {
                                $orderedIds[] = $oid;
                            } else {
                                if (isset($bestById[$oid]) && \in_array($bestById[$oid], $targets, true)) {
                                    $orderedIds[] = $oid;
                                }
                            }
                        }

                        $prevId = null; $nextId = null;
                        if ($orderedIds) {
                            $pos = \array_search($id, $orderedIds, true);
                            if ($pos !== false) {
                                if ($pos > 0) { $prevId = $orderedIds[$pos - 1]; }
                                if ($pos < \count($orderedIds) - 1) { $nextId = $orderedIds[$pos + 1]; }
                            }
                        }

                        return $this->render('admin/orders/process.html.twig', [
                            'o'                   => $o,
                            'meta'                => $meta,
                            'lines'               => $lines,
                            'lines_current'       => $linesCurrent,
                            'prepared_lines_current' => $preparedLinesCurrent,
                            'local_lines_by_pid'  => $localLinesByPid,
                            'ship'                => $ship,
                            'users'               => $users,
                            'odoo_state'          => $odooState,
                            'picking'             => $pickingData,
                            'picking_lines_by_pid'=> $pickingLinesByPid,
                            'pickings'            => $pickings,
                            'product_pickings'    => $productPickings,
                            'products_info_by_pid'=> $productsInfoByPid,
                            'so_lines_by_pid'     => $soLinesByPid,
                            'backorder_pickings'  => $backorderPickings,
                            'all_pickings'        => $allPickings,
                            // Navigation
                            'prev_id'             => $prevId,
                            'next_id'             => $nextId,
                            'list_query'          => $listQuery,
                    ]);
    }

    #[Route('/admin/orders/{id<\\d+>}/save-prep', name: 'admin_orders_save_prep', methods: ['POST'])]
    public function savePreparation(int $id, Request $req): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $prepared = (array) $req->request->all('prepared'); // line_id => qty
        foreach ($prepared as $lineId => $qty) {
            if (!is_numeric($lineId)) { continue; }
            $this->db->executeStatement(
                'UPDATE sales_order_lines SET prepared_qty = ? WHERE id = ? AND order_id = ?',
                [(float)$qty, (int)$lineId, $id]
            );
        }

        $this->addFlash('success', 'Préparation enregistrée.');
        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }
}

<?php /** @noinspection ALL */
/** @noinspection PhpUnusedLocalVariableInspection */
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
     * @noinspection PhpUnusedLocalVariableInspection
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
        /** @noinspection PhpUnusedLocalVariableInspection */
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

                        return $this->render('admin/orders/process.html.twig', [
                            'o'                   => $o,
                            'meta'                => $meta,
                            'lines'               => $lines,
                            'ship'                => $ship,
                            'users'               => $users,
                            'odoo_state'          => $odooState,
                            'picking'             => $pickingData,
                            'picking_lines_by_pid'=> $pickingLinesByPid,
                            'pickings'            => $pickings,
                            'product_pickings'    => $productPickings,
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

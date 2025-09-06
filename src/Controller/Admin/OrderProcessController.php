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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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
            $this->db->executeStatement(
                'INSERT INTO sales_order_meta (order_id, parcels_count, package_ref, prepared_by, ship_name, ship_phone, ship_address1, ship_address2, ship_postcode, ship_city, ship_country)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   parcels_count = VALUES(parcels_count),
                   package_ref   = VALUES(package_ref),
                   prepared_by   = VALUES(prepared_by),
                   ship_name     = VALUES(ship_name),
                   ship_phone    = VALUES(ship_phone),
                   ship_address1 = VALUES(ship_address1),
                   ship_address2 = VALUES(ship_address2),
                   ship_postcode = VALUES(ship_postcode),
                   ship_city     = VALUES(ship_city),
                   ship_country  = VALUES(ship_country)',
                [
                    $id,
                    $parcels,
                    (string)$p->get('package_ref'),
                    (string)$p->get('prepared_by', (string)$this->getUser()?->getUserIdentifier()),
                    (string)$p->get('ship_name'),
                    (string)$p->get('ship_phone'),
                    (string)$p->get('ship_address1'),
                    (string)$p->get('ship_address2'),
                    (string)$p->get('ship_postcode'),
                    (string)$p->get('ship_city'),
                    (string)$p->get('ship_country'),
                ]
            );

            // 2) Transport / tracking
            $this->db->executeStatement(
                'UPDATE sales_orders SET shipping_carrier = ?, shipping_service = ?, tracking_number = ?, updated_at = NOW() WHERE id = ?',
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

        // Dropdown préparateur (tolérant)
        /** @noinspection PhpUnusedLocalVariableInspection */
        $users = [];
        try {
            $users = $this->db->fetchAllAssociative('SELECT COALESCE(full_name, name, username, email) AS label FROM user ORDER BY label');
        } catch (Throwable) {
            try {
                $users = $this->db->fetchAllAssociative('SELECT COALESCE(full_name, name, username, email) AS label FROM users ORDER BY label');
            } catch (Throwable) {
                $users = [['label' => ($this->getUser()?->getUserIdentifier() ?? 'admin')]];
            }
        }

        return $this->render('admin/orders/process.html.twig', [
            'o'          => $o,
            'meta'       => $meta,
            'lines'      => $lines,
            'ship'       => $ship,
            'users'      => $users,
            'odoo_state' => $odooState,
        ]);
    }
}


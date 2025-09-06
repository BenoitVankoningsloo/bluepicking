<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSalesService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class OdooController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @throws Exception
     */
    #[Route('/admin/odoo/orders/import/{ref}', name: 'admin_odoo_import_order', methods: ['GET'])]
    public function import(string $ref, OdooSalesService $svc): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $data = $svc->fetchSaleOrder($ref);
        // Upsert minimal dans tes tables (sales_orders / sales_order_lines)
        $so = $data['so']; $partner = $data['partner']; $lines = $data['lines'];

        // 1) Order
        $this->db->executeStatement(
            'INSERT INTO sales_orders (external_order_id, source, status, customer_name, customer_email,
                                       shipping_carrier, shipping_service, placed_at, payload_json, currency, item_count,
                                       odoo_sale_order_id, odoo_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status=VALUES(status), customer_name=VALUES(customer_name),
                 customer_email=VALUES(customer_email), payload_json=VALUES(payload_json), item_count=VALUES(item_count)',
            [
                $so['name'], 'odoo', $so['state'],
                $partner['name'] ?? '', $partner['email'] ?? '',
                '','',
                $so['date_order'] ?? null,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                '', count($lines),
                $so['id'], $so['name'],
            ]
        );
        $local = $this->db->fetchAssociative('SELECT id FROM sales_orders WHERE external_order_id = ?', [$so['name']]);
        $orderId = (int)$local['id'];

        // 2) Lines (purge-reinsertion simple)
        $this->db->executeStatement('DELETE FROM sales_order_lines WHERE order_id = ?', [$orderId]);
        foreach ($lines as $l) {
            $prodName = is_array($l['product_id']) ? $l['product_id'][1] : $l['name'];
            $sku = $prodName; // à remplacer par ton mapping SKU si dispo
            $this->db->executeStatement(
                'INSERT INTO sales_order_lines (order_id, sku, name, qty, unit_price, odoo_line_id)
                 VALUES (?, ?, ?, ?, 0, ?)',
                [$orderId, $sku, $prodName, (float)$l['product_uom_qty'], (int)$l['id']]
            );
        }

        $this->addFlash('success', sprintf('Import Odoo OK: %s (%d lignes)', $so['name'], count($lines)));
        return $this->redirectToRoute('admin_orders_show', ['id' => $orderId]);
    }

    /**
     * @throws Exception
     */
    #[Route('/admin/odoo/orders/{id<\\d+>}/confirm', name: 'admin_odoo_confirm_order', methods: ['POST'])]
    public function confirm(int $id, Request $req, OdooSalesService $svc): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $token = (string)$req->request->get('_token', '');
        if (!$this->isCsrfTokenValid('odoo_confirm_'.$id, $token)) {
            $this->addFlash('danger', 'CSRF invalide');
            return $this->redirectToRoute('admin_orders_show', ['id' => $id]);
        }

        $o = $this->db->fetchAssociative('SELECT odoo_sale_order_id FROM sales_orders WHERE id = ?', [$id]);
        if (!$o || !$o['odoo_sale_order_id']) {
            $this->addFlash('danger', 'Aucun odoo_sale_order_id sur cette commande');
            return $this->redirectToRoute('admin_orders_show', ['id' => $id]);
        }

        $svc->confirmSaleOrder((int)$o['odoo_sale_order_id']);
        $this->addFlash('success', 'Commande Odoo confirmée');
        return $this->redirectToRoute('admin_orders_show', ['id' => $id]);
    }
}


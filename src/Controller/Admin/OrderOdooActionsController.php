<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSyncService;
use App\Service\OdooSalesService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class OrderOdooActionsController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Import Odoo par référence (SO "name" ex: S00042) OU batch si ref vide.
     * - POST + ref => vérifie le CSRF
     * - GET accepté pour lancer un batch depuis un lien (POC)
     */
    #[Route('/admin/odoo/orders/import', name: 'admin_odoo_import_by_ref', methods: ['POST','GET'])]
    public function importByRef(Request $req, OdooSyncService $sync): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $method = $req->getMethod();
        $ref = trim((string)($method === 'POST' ? $req->request->get('ref') : $req->query->get('ref', '')));

        if ($method === 'POST' && $ref !== '') {
            $token = (string)$req->request->get('_token', '');
            if (!$this->isCsrfTokenValid('odoo_import', $token)) {
                $this->addFlash('danger', 'CSRF invalide.');
                return $this->redirectToRoute('admin_orders_list');
            }
        }

        try {
            if ($ref === '') {
                // Batch par défaut: 30 derniers jours, états principaux
                $since  = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
                $states = ['draft','sent','sale','done','cancel'];

                // Doit retourner ['imported'=>int,'last_ref'=>?string,'errors'=>int]
                $res = $sync->syncBatch($states, $since, null, 500, 0);
                $imported = (int)($res['imported'] ?? 0);
                $errors   = (int)($res['errors'] ?? 0);
                $lastRef  = $res['last_ref'] ?? '—';

                $this->addFlash(
                    'success',
                    sprintf(
                        'Batch Odoo: %d importés%s. Période: depuis %s. Dernier: %s.',
                        $imported,
                        $errors ? " · erreurs: {$errors}" : '',
                        $since,
                        $lastRef
                    )
                );

                return $this->redirectToRoute('admin_orders_list');
            }

            // Import d’une seule SO (par id ou par name)
            $orderId = $sync->syncOne($ref);
            $this->addFlash('success', 'Import Odoo OK : '.$ref);
            return $this->redirectToRoute('admin_orders_show', ['id' => $orderId]);

        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Import Odoo: '.$e->getMessage());
            return $this->redirectToRoute('admin_orders_list');
        }
    }

    /**
     * Confirmer la vente dans Odoo (action_confirm)
     * - Bloqué si déjà sale/done/cancel
     */
    #[Route('/admin/orders/{id<\d+>}/odoo/confirm', name: 'admin_odoo_confirm_order', methods: ['POST'])]
    public function confirm(int $id, Request $req, OdooSalesService $svc): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('odoo_confirm_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }

        $o = $this->db->fetchAssociative('SELECT odoo_sale_order_id FROM sales_orders WHERE id=?', [$id]);
        if (!$o || !$o['odoo_sale_order_id']) {
            $this->addFlash('danger', 'Aucun odoo_sale_order_id');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }
        $soId = (int)$o['odoo_sale_order_id'];

        try {
            $state = $svc->getSaleOrderState($soId);
            if (in_array($state, ['sale','done','cancel'], true)) {
                $this->addFlash('info', 'Commande déjà confirmée/livrée ou annulée (état: '.$state.').');
                return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
            }
            $svc->confirmSaleOrder($soId);
            $this->addFlash('success', 'Commande Odoo confirmée.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }

    /**
     * Annuler la vente dans Odoo (action_cancel)
     * - Bloqué si déjà cancel
     */
    #[Route('/admin/orders/{id<\d+>}/odoo/cancel', name: 'admin_odoo_cancel_order', methods: ['POST'])]
    public function cancel(int $id, Request $req, OdooSalesService $svc): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('odoo_cancel_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }

        $o = $this->db->fetchAssociative('SELECT odoo_sale_order_id FROM sales_orders WHERE id=?', [$id]);
        if (!$o || !$o['odoo_sale_order_id']) {
            $this->addFlash('danger', 'Aucun odoo_sale_order_id');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }
        $soId = (int)$o['odoo_sale_order_id'];

        try {
            $state = $svc->getSaleOrderState($soId);
            if ($state === 'cancel') {
                $this->addFlash('info', 'La commande est déjà annulée dans Odoo.');
                return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
            }
            $svc->cancelSaleOrder($soId);
            $this->addFlash('success', 'Commande Odoo annulée.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }

    /**
     * Rafraîchir les lignes/stock depuis Odoo vers la DB locale
     */
    #[Route('/admin/orders/{id<\d+>}/odoo/refresh-lines', name: 'admin_odoo_refresh_lines', methods: ['POST'])]
    public function refreshLines(int $id, Request $req, OdooSalesService $svc): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('odoo_refresh_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }

        try {
            $svc->refreshLocalOrderLines($this->db, $id);
            $this->addFlash('success', 'Lignes + stock rafraîchis depuis Odoo');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }

    /**
     * Pousser les quantités préparées et valider le picking dans Odoo.
     * Puis marquer la commande comme "verrouillée" côté Bluepicking (picking_validated_at).
     */
    #[Route('/admin/orders/{id<\d+>}/odoo/picking/push-validate', name: 'admin_odoo_push_validate', methods: ['POST'])]
    public function pushValidate(int $id, Request $req, OdooSalesService $svc): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('odoo_pushval_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }

        // Enregistre prepared_qty postées
        $prepared = $req->request->all('prepared');
        if ($prepared) {
            foreach ($prepared as $lineId => $qty) {
                $q = max(0, (float)$qty);
                $this->db->executeStatement(
                    'UPDATE sales_order_lines SET prepared_qty=? WHERE id=? AND order_id=?',
                    [$q, (int)$lineId, $id]
                );
            }
        }

        try {
            $svc->pushPreparedAndValidate($this->db, $id);

            // Verrouiller côté Bluepicking
            $this->db->executeStatement(
                'INSERT INTO sales_order_meta (order_id, picking_validated_at)
                 VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE picking_validated_at = NOW()',
                [$id]
            );

            $this->addFlash('success', 'Bon de livraison validé dans Odoo (stock à jour).');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }
}


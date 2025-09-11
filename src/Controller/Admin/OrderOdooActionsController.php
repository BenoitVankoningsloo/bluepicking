<?php
/** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSyncService;
use App\Service\OdooSalesService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

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
                // Batch: since=all (total) ou défaut 30 derniers jours, états principaux
                $sinceParam = \trim((string)$req->query->get('since', ''));
                $since = null;
                if ($sinceParam === '' || strtolower($sinceParam) === '30d') {
                    $since = (new DateTimeImmutable('-30 days'))->format('Y-m-d');
                } elseif (strtolower($sinceParam) === 'all') {
                    $since = null; // import complet
                } elseif (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceParam)) {
                    $since = $sinceParam;
                }

                // 1) Commandes (inclut delivery_status depuis sale.order)
                $orderStates = ['draft','sent','sale','done','cancel'];
                $resOrders = $sync->syncBatch($orderStates, $since);
                $ordersImported = (int)($resOrders['imported'] ?? 0);
                $ordersErrors   = (int)($resOrders['errors'] ?? 0);
                $ordersLastRef  = $resOrders['last_ref'] ?? '—';

                // 2) Pickings (stock.picking) pour tenir la table locale odoo_pickings à jour
                $pickingStates = ['draft','waiting','confirmed','assigned','done','cancel'];
                $resPickings = $sync->pickings($pickingStates, $since, null, 500, 0);
                $pickingsImported = (int)($resPickings['imported'] ?? 0);

                $this->addFlash(
                    'success',
                    sprintf(
                        'Batch Odoo: commandes %d importées%s (dernier: %s) · pickings %d importés. Période: depuis %s.',
                        $ordersImported,
                        $ordersErrors ? " · erreurs: {$ordersErrors}" : '',
                        $ordersLastRef,
                        $pickingsImported,
                        $since
                    )
                );

                return $this->redirectToRoute('admin_orders_list');
            }

            // Import d’une seule SO (par id ou par name)
            $orderId = $sync->syncOne($ref);
            $this->addFlash('success', 'Import Odoo OK : '.$ref);
            return $this->redirectToRoute('admin_orders_show', ['id' => $orderId]);

        } catch (Throwable $e) {
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
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

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
        } catch (Throwable $e) {
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
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

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
        } catch (Throwable $e) {
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
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('odoo_refresh_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide');
            return $this->redirectToRoute('admin_orders_process', ['id'=>$id]);
        }

        try {
            $svc->refreshLocalOrderLines($this->db, $id);
            $this->addFlash('success', 'Lignes + stock rafraîchis depuis Odoo');
        } catch (Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }

    /**
     * Pousser les quantités préparées et valider le picking dans Odoo.
     * Puis marquer la commande comme "verrouillée" côté Bluepicking (picking_validated_at).
     */
    #[Route('/admin/orders/{id<\d+>}/odoo/picking/push-validate', name: 'admin_odoo_push_validate', methods: ['POST'])]
    public function pushValidate(int $id, Request $req, OdooSalesService $svc, OdooSyncService $sync): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

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

            // Verrouiller côté Bluepicking (portable SQLite/MySQL)
            $affected = $this->db->executeStatement(
                'UPDATE sales_order_meta SET picking_validated_at = CURRENT_TIMESTAMP WHERE order_id = ?',
                [$id]
            );
            if ($affected === 0) {
                $this->db->executeStatement(
                    'INSERT INTO sales_order_meta (order_id, picking_validated_at) VALUES (?, CURRENT_TIMESTAMP)',
                    [$id]
                );
            }

            // Rafraîchir l'entête de commande depuis Odoo pour mettre à jour delivery_status localement
            try {
                $row = $this->db->fetchAssociative('SELECT odoo_sale_order_id, odoo_name FROM sales_orders WHERE id=?', [$id]);
                if ($row && !empty($row['odoo_sale_order_id'])) {
                    $sync->syncOne((int)$row['odoo_sale_order_id']);
                } elseif ($row && !empty($row['odoo_name'])) {
                    $sync->syncOne((string)$row['odoo_name']);
                }
            } catch (Throwable $ignored) {
                // on ne bloque pas l'utilisateur si la resynchro échoue
            }

            $this->addFlash('success', 'Bon de livraison validé dans Odoo (stock à jour) · Entête resynchronisée.');
        } catch (Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }
}


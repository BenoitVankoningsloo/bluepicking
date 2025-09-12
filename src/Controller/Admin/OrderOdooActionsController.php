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

        // Pré-validation bloquante: refuse si la quantité saisie dépasse le restant à préparer
        $prepared = (array)$req->request->all('prepared'); // line_id => qty
        if ($prepared) {
            // 1) Rassembler les line_ids et récupérer odoo_product_id
            $lineIds = [];
            foreach ($prepared as $lineId => $qty) {
                if (is_numeric($lineId)) { $lineIds[] = (int)$lineId; }
            }

            $preparedByPid = [];
            $namesByPid = [];
            if ($lineIds) {
                $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
                $rows = $this->db->fetchAllAssociative(
                    'SELECT id, odoo_product_id, name FROM sales_order_lines WHERE order_id = ? AND id IN ('.$placeholders.')',
                    array_merge([$id], $lineIds)
                );
                $pidByLine = [];
                foreach ($rows as $r) {
                    $lid = (int)$r['id'];
                    $pid = (int)($r['odoo_product_id'] ?? 0);
                    $pidByLine[$lid] = $pid;
                    if ($pid > 0 && !isset($namesByPid[$pid])) {
                        $namesByPid[$pid] = (string)($r['name'] ?? ('Produit '.$pid));
                    }
                }
                foreach ($prepared as $lineId => $qty) {
                    $pid = $pidByLine[(int)$lineId] ?? 0;
                    if ($pid > 0) {
                        $preparedByPid[$pid] = ($preparedByPid[$pid] ?? 0.0) + max(0.0, (float)$qty);
                    }
                }
            }

            // 2) Récupérer le restant par produit via Odoo
            $orderRef = null;
            $row = $this->db->fetchAssociative('SELECT odoo_sale_order_id, odoo_name FROM sales_orders WHERE id=?', [$id]);
            if ($row) {
                $orderRef = !empty($row['odoo_sale_order_id']) ? (int)$row['odoo_sale_order_id'] : ((string)($row['odoo_name'] ?? ''));
            }
            $remainingByPid = $orderRef !== null && $orderRef !== '' ? $svc->getRemainingByProductForOrder($orderRef) : [];

            // 3) Comparer et bloquer si dépassement
            $errors = [];
            foreach ($preparedByPid as $pid => $qty) {
                $allowed = (float)($remainingByPid[$pid] ?? INF);
                if ($qty > $allowed + 1e-9) {
                    $label = $namesByPid[$pid] ?? ('Produit '.$pid);
                    $errors[] = sprintf('%s: saisi %.2f > restant %.2f', $label, $qty, $allowed);
                }
            }
            if ($errors) {
                $this->addFlash('danger', 'Quantité préparée trop élevée pour: ' . implode(' · ', $errors));
                return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
            }

            // 3-bis) Vérifier le stock disponible (qty_available) pour éviter une validation avec stock négatif
            //        Autoriser explicitement les saisies à 0 (Odoo créera un backorder).
            $stockErrors = [];
            if ($preparedByPid) {
                try {
                    // Lecture Odoo du stock dispo par produit
                    $info = $svc->getProductsInfo(array_keys($preparedByPid));
                    foreach ($preparedByPid as $pid => $q) {
                        $avail = isset($info[$pid]['qty_available']) ? (float)$info[$pid]['qty_available'] : null;
                        if ($avail !== null && $q > 0 && $q > $avail + 1e-9) {
                            $label = $namesByPid[$pid] ?? ('Produit '.$pid);
                            $stockErrors[] = sprintf('%s: saisi %.2f > stock disponible %.2f', $label, $q, $avail);
                        }
                    }
                } catch (\Throwable $e) {
                    // si la lecture stock échoue, on ne bloque pas ici
                }
            }
            if ($stockErrors) {
                $this->addFlash('danger', 'Stock insuffisant (validation impossible) pour: ' . implode(' · ', $stockErrors));
                return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
            }

            // 4) Si OK → enregistrer les quantités
            foreach ($prepared as $lineId => $qty) {
                if (!is_numeric($lineId)) { continue; }
                $this->db->executeStatement(
                    'UPDATE sales_order_lines SET prepared_qty = ? WHERE id = ? AND order_id = ?',
                    [max(0, (float)$qty), (int)$lineId, $id]
                );
            }
        }

        try {
            // Case à cocher: créer un reliquat (backorder) pour les quantités non préparées ?
            $createBackorder = (bool)$req->request->get('create_backorder', true);

            $svc->pushPreparedAndValidate($this->db, $id, $createBackorder);

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
            $msg = (string)$e->getMessage();
            // Si Odoo renvoie l’erreur “unhashable type: 'list'” (souvent liée aux quantités partielles),
            // on force explicitement la création d’un reliquat et on informe l’utilisateur.
            if (stripos($msg, "unhashable type: 'list'") !== false) {
                try {
                    $row = $this->db->fetchAssociative('SELECT odoo_sale_order_id, odoo_name FROM sales_orders WHERE id=?', [$id]);
                    $ref = null;
                    if ($row) {
                        $ref = !empty($row['odoo_sale_order_id']) ? (int)$row['odoo_sale_order_id'] : ((string)($row['odoo_name'] ?? ''));
                    }
                    if ($ref !== null && $ref !== '') {
                        $svc->forceBackorderForOrder($ref);
                        $this->addFlash('info', "Validation partielle: reliquat créé automatiquement dans Odoo.");
                        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
                    }
                } catch (Throwable $e2) {
                    // si le fallback échoue, on retombe sur le message d'erreur initial
                }
            }
            $this->addFlash('danger', 'Odoo: '.$msg);
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }
}


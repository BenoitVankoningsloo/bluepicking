<?php
/** @noinspection ALL */
declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class OdooSyncService
{
    public function __construct(
        private readonly Connection $db,
        private readonly OdooSalesService $sales
    ) {}

    public function upsertFromOdooPayload(array $data): int
    {
        $so      = $data['so'] ?? [];
        $partner = $data['partner'] ?? [];
        $lines   = $data['lines'] ?? [];

        if (!$so || !isset($so['id'], $so['name'])) {
            throw new \RuntimeException('Payload Odoo invalide (so.id/so.name manquants)');
        }

        // Montant total & devise
        $amountTotal  = (float)($so['amount_total'] ?? 0);
        $currencyCode = 'EUR';
        if (isset($so['currency_id'])) {
            // Odoo renvoie souvent ['id', 'EUR']
            if (is_array($so['currency_id']) && isset($so['currency_id'][1])) {
                $currencyCode = (string)$so['currency_id'][1];
            }
        }

        $this->db->beginTransaction();
        try {
            // 1) Upsert entête
            $this->db->executeStatement(
                'INSERT INTO sales_orders (
                    external_order_id, source, status, customer_name, customer_email,
                    placed_at, payload_json, item_count,
                    odoo_sale_order_id, odoo_name, odoo_synced_at,
                    total_amount, currency
                 ) VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), ?, ?)
                 ON DUPLICATE KEY UPDATE
                    source=VALUES(source),
                    status=VALUES(status),
                    customer_name=VALUES(customer_name),
                    customer_email=VALUES(customer_email),
                    placed_at=VALUES(placed_at),
                    payload_json=VALUES(payload_json),
                    item_count=VALUES(item_count),
                    odoo_sale_order_id=VALUES(odoo_sale_order_id),
                    odoo_name=VALUES(odoo_name),
                    odoo_synced_at=VALUES(odoo_synced_at),
                    total_amount=VALUES(total_amount),
                    currency=VALUES(currency)',
                [
                    $so['name'], 'odoo', (string)$so['state'],
                    $partner['name'] ?? '', $partner['email'] ?? '',
                    $so['date_order'] ?? null,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    is_countable($lines) ? count($lines) : 0,
                    (int)$so['id'], (string)$so['name'],
                    $amountTotal, $currencyCode,
                ]
            );

            // ID local
            $local = $this->db->fetchAssociative('SELECT id FROM sales_orders WHERE odoo_sale_order_id = ?', [(int)$so['id']])
                  ?: $this->db->fetchAssociative('SELECT id FROM sales_orders WHERE external_order_id = ?', [(string)$so['name']]);
            if (!$local) { throw new \RuntimeException('Upsert SO: impossible de récupérer l’ID local'); }
            $orderId = (int)$local['id'];

            // 2) Lignes (purge + insert)
            $this->db->executeStatement('DELETE FROM sales_order_lines WHERE order_id = ?', [$orderId]);
            foreach ($lines as $l) {
                $pid   = is_array($l['product_id']) ? ($l['product_id'][0] ?? null) : null;
                $pname = is_array($l['product_id']) ? ($l['product_id'][1] ?? ($l['name'] ?? '')) : ($l['name'] ?? '');
                $this->db->executeStatement(
                    'INSERT INTO sales_order_lines
                        (order_id, odoo_line_id, odoo_product_id, sku, name, qty, unit_price, prepared_qty, odoo_qty_available)
                     VALUES (?,?,?,?,?,?,0,NULL,NULL)',
                    [$orderId, (int)$l['id'], $pid, (string)$pname, (string)$pname, (float)$l['product_uom_qty']]
                );
            }

            // 3) Maj stock Odoo sur les lignes locales
            $this->sales->refreshLocalOrderLines($this->db, $orderId);

            $this->db->commit();
            return $orderId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function syncOne(string|int $refOrId): int
    {
        $data = $this->sales->fetchSaleOrder($refOrId);
        return $this->upsertFromOdooPayload($data);
    }

    public function listSaleOrdersMinimal(array $domain, int $limit = 500, int $offset = 0): array
    {
        return $this->sales->listSaleOrdersMinimal($domain, $limit, $offset);
    }

    public function syncBatch(array $states, ?string $since = null, ?string $until = null, int $limit = 500, int $offset = 0): array
    {
       $domain = [];
    if ($states) { $domain[] = ['state', 'in', array_values($states)]; }
    if ($since)  { $domain[] = ['date_order', '>=', $since]; }
    if ($until)  { $domain[] = ['date_order', '<=', $until]; }

    $orders = $this->listSaleOrdersMinimal($domain, $limit, $offset);

    $count = 0; $lastRef = null; $errors = 0;
    foreach ($orders as $row) {
        try {
            $this->syncOne((int)$row['id']);
            $count++; $lastRef = (string)$row['name'];
        } catch (\Throwable $e) {
            $errors++;
            // on continue
        }
    }
    return ['imported' => $count, 'last_ref' => $lastRef, 'errors' => $errors];
    }
}


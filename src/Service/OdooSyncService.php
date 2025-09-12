<?php
declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class OdooSyncService
{
    public function __construct(
        private readonly Connection $db,
        private readonly OdooSalesService $sales,
        private readonly LoggerInterface $logger,
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

        // S'assure de la présence de la colonne delivery_status (portable SQLite/MySQL)
        $this->ensureSalesOrdersColumns();

        $this->db->beginTransaction();
        try {
            // 1) Upsert entête (portable SQLite/MySQL)
            $extRef = (string)$so['name'];
            $odooId = (int)$so['id'];
            $dlv    = (string)($so['delivery_status'] ?? 'no');

            // Trace pour contrôle de la valeur remontée par Odoo
            $this->logger->info('Odoo import SO', ['name' => $extRef, 'odoo_id' => $odooId, 'delivery_status' => $dlv]);

            // Cherche une ligne existante par odoo_sale_order_id ou external_order_id
            $existing = $this->db->fetchAssociative(
                'SELECT id FROM sales_orders WHERE odoo_sale_order_id = ? OR external_order_id = ? LIMIT 1',
                [$odooId, $extRef]
            );

            if ($existing) {
                // Mise à jour
                $this->db->executeStatement(
                    'UPDATE sales_orders
                        SET external_order_id = ?, source = ?, status = ?, delivery_status = ?, customer_name = ?, customer_email = ?,
                            placed_at = ?, payload_json = ?, item_count = ?,
                            odoo_sale_order_id = ?, odoo_name = ?, odoo_synced_at = CURRENT_TIMESTAMP,
                            total_amount = ?, currency = ?
                      WHERE id = ?',
                    [
                        $extRef, 'odoo', (string)$so['state'], $dlv,
                        $partner['name'] ?? '', $partner['email'] ?? '',
                        $so['date_order'] ?? null,
                        json_encode($data, JSON_UNESCAPED_UNICODE),
                        is_countable($lines) ? count($lines) : 0,
                        $odooId, (string)$so['name'],
                        $amountTotal, $currencyCode,
                        (int)$existing['id'],
                    ]
                );

                // Log post-mise à jour
                $this->logger->info('SO mise à jour', [
                    'id' => (int)$existing['id'],
                    'delivery_status_new' => $dlv
                ]);
            } else {
                // Insertion
                $this->db->executeStatement(
                    'INSERT INTO sales_orders (
                        external_order_id, source, status, delivery_status, customer_name, customer_email,
                        placed_at, payload_json, item_count,
                        odoo_sale_order_id, odoo_name, odoo_synced_at,
                        total_amount, currency
                     ) VALUES (?,?,?,?,?,?,?, ?,?,?,?, CURRENT_TIMESTAMP, ?, ?)',
                    [
                        $extRef, 'odoo', (string)$so['state'], $dlv,
                        $partner['name'] ?? '', $partner['email'] ?? '',
                        $so['date_order'] ?? null,
                        json_encode($data, JSON_UNESCAPED_UNICODE),
                        is_countable($lines) ? count($lines) : 0,
                        $odooId, (string)$so['name'],
                        $amountTotal, $currencyCode,
                    ]
                );

                // Log post-insertion
                $this->logger->info('SO insérée', [
                    'external_order_id' => $extRef,
                    'odoo_id' => $odooId,
                    'delivery_status' => $dlv
                ]);
            }

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

        /**
         * Synchronise les ordres de livraison (stock.picking) depuis Odoo
         * vers la table locale odoo_pickings (redondance BD).
         * Filtre par états et période sur scheduled_date.
         */
        public function syncPickings(array $states, ?string $since = null, ?string $until = null, int $limit = 500, int $offset = 0): array
        {
            $this->ensurePickingsTable();

            $domain = [];
            if ($states) { $domain[] = ['state', 'in', array_values($states)]; }
            // On cible les mouvements sortants (livraisons)
            $domain[] = ['picking_type_code', '=', 'outgoing'];
            if ($since) { $domain[] = ['scheduled_date', '>=', $since . ' 00:00:00']; }
            if ($until) { $domain[] = ['scheduled_date', '<=', $until . ' 23:59:59']; }

            $fields = ['name','origin','partner_id','scheduled_date','state','write_date'];
            $rows = $this->sales->listPickings($domain, $limit, $offset, $fields);
            if (!is_array($rows)) {
                throw new \RuntimeException('Odoo: réponse inattendue pour stock.picking');
            }

            $this->db->beginTransaction();
            try {
                foreach ($rows as $r) {
                    $odooId   = (int)($r['id'] ?? 0);
                    $name     = (string)($r['name'] ?? '');
                    $origin   = (string)($r['origin'] ?? '');
                    $state    = (string)($r['state'] ?? '');
                    $schedRaw = (string)($r['scheduled_date'] ?? '');
                    $scheduled= $schedRaw !== '' ? $schedRaw : null;
                    $writeRaw = (string)($r['write_date'] ?? '');
                    $updated  = $writeRaw !== '' ? $writeRaw : null;

                    $partnerId   = null;
                    $partnerName = null;
                    if (isset($r['partner_id'])) {
                        if (is_array($r['partner_id'])) {
                            $partnerId   = isset($r['partner_id'][0]) ? (int)$r['partner_id'][0] : null;
                            $partnerName = isset($r['partner_id'][1]) ? (string)$r['partner_id'][1] : null;
                        } elseif (is_int($r['partner_id'])) {
                            $partnerId = (int)$r['partner_id'];
                        }
                    }

                    $payload = json_encode($r, JSON_UNESCAPED_UNICODE);

                    // UPDATE d’abord
                    $affected = $this->db->executeStatement(
                        'UPDATE odoo_pickings
                            SET name = ?, origin = ?, partner_id = ?, partner_name = ?, scheduled_date = ?, state = ?, updated_at = ?, payload_json = ?
                          WHERE odoo_id = ?',
                        [$name, $origin, $partnerId, $partnerName, $scheduled, $state, $updated, $payload, $odooId]
                    );

                    // Si aucune ligne modifiée, n'insérer QUE si la ligne n'existe pas (évite duplicate key)
                    if ($affected === 0) {
                        $exists = $this->db->fetchOne('SELECT 1 FROM odoo_pickings WHERE odoo_id = ?', [$odooId]);
                        if (!$exists) {
                            $this->db->executeStatement(
                                'INSERT INTO odoo_pickings
                                    (odoo_id, name, origin, partner_id, partner_name, scheduled_date, state, updated_at, payload_json)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$odooId, $name, $origin, $partnerId, $partnerName, $scheduled, $state, $updated, $payload]
                            );
                        }
                    }
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            return ['imported' => count($rows)];
        }

        /** Alias simple pour synchroniser les pickings (appel dynamique) */
        public function pickings(array $states = [], ?string $since = null, ?string $until = null, int $limit = 500, int $offset = 0): array
        {
            return $this->syncPickings($states, $since, $until, $limit, $offset);
        }

        /** Création portable de la table odoo_pickings (SQLite/MySQL) + index */
        private function ensurePickingsTable(): void
        {
            try {
                // Variante SQLite (AUTOINCREMENT/INTEGER PRIMARY KEY)
                $this->db->executeStatement(
                    'CREATE TABLE IF NOT EXISTS odoo_pickings (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        odoo_id INTEGER UNIQUE,
                        name VARCHAR(64) NOT NULL,
                        origin VARCHAR(128) NULL,
                        partner_id INTEGER NULL,
                        partner_name VARCHAR(255) NULL,
                        scheduled_date DATETIME NULL,
                        state VARCHAR(32) NOT NULL,
                        updated_at DATETIME NULL,
                        payload_json TEXT NULL
                     )'
                );
            } catch (\Throwable) {
                // Variante MySQL
                $this->db->executeStatement(
                    'CREATE TABLE IF NOT EXISTS odoo_pickings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        odoo_id INT UNIQUE,
                        name VARCHAR(64) NOT NULL,
                        origin VARCHAR(128) NULL,
                        partner_id INT NULL,
                        partner_name VARCHAR(255) NULL,
                        scheduled_date DATETIME NULL,
                        state VARCHAR(32) NOT NULL,
                        updated_at DATETIME NULL,
                        payload_json LONGTEXT NULL
                     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            }

            // Index non bloquants
            try { $this->db->executeStatement('CREATE INDEX IF NOT EXISTS idx_pickings_state ON odoo_pickings(state)'); } catch (\Throwable) {}
            try { $this->db->executeStatement('CREATE INDEX IF NOT EXISTS idx_pickings_sched ON odoo_pickings(scheduled_date)'); } catch (\Throwable) {}
            try { $this->db->executeStatement('CREATE INDEX IF NOT EXISTS idx_pickings_partner ON odoo_pickings(partner_id)'); } catch (\Throwable) {}
        }

        /**
         * Ajoute la colonne delivery_status si absente (portable SQLite/MySQL).
         * Tolère les erreurs si la colonne existe déjà.
         */
        private function ensureSalesOrdersColumns(): void
        {
            try {
                $this->db->executeStatement('ALTER TABLE sales_orders ADD COLUMN delivery_status VARCHAR(32) NULL');
            } catch (\Throwable) {
                // colonne déjà présente ou autre SGBD: on ignore
            }
        }

}


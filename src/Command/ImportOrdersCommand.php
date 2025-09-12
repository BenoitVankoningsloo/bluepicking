<?php declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Generator;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'bluepicking:import:orders',
    description: 'Import CSV dans la table sales_orders (upsert par external_order_id)'
)]
class ImportOrdersCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du CSV UTF-8')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'Délimiteur CSV')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire')
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'VIDE la table avant import (DANGER)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Taille de lot transactionnelle', 500);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string)$input->getArgument('file');
        $delimiter = $input->getOption('delimiter');
        $dryRun = (bool)$input->getOption('dry-run');
        $truncate = (bool)$input->getOption('truncate');
        $batchSize = (int)$input->getOption('batch-size');

        if (!is_file($path)) { $io->error("Fichier introuvable: $path"); return Command::FAILURE; }

        $rows = $this->iterateCsv($path, $delimiter);
        $required = ['external_order_id','status','total_amount','currency','item_count','source'];

        $inserted = 0; $updated = 0; $skipped = 0; $seen = 0;

        if ($truncate && !$dryRun) {
            $this->db->executeStatement('TRUNCATE TABLE sales_orders');
            $io->warning('TRUNCATE exécuté sur sales_orders.');
        }

        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $seen++;

                foreach ($required as $key) {
                    if (!array_key_exists($key, $row) || $row[$key] == '') {
                        $skipped++;
                        if ($io->isVerbose()) $io->note("Ligne $seen ignorée (champ $key manquant).");
                        continue 2;
                    }
                }

                $extId = trim((string)$row['external_order_id']);
                $status= trim((string)$row['status']);
                $delivery = isset($row['delivery_status']) ? trim((string)$row['delivery_status']) : null;
                $custN = $row['customer_name'] ?? null;
                $custE = $row['customer_email'] ?? null;
                $total = $this->toDecimal($row['total_amount']);
                $cur   = strtoupper(trim((string)$row['currency'] ?: 'EUR'));
                $items = (int)$row['item_count'];
                $source= trim((string)$row['source'] ?: 'manual');
                $carrier = $row['shipping_carrier'] ?? null;
                $service = $row['shipping_service'] ?? null;
                $tracking= $row['tracking_number'] ?? null;
                $placed  = $this->toDateTimeNullable($row['placed_at'] ?? null);
                $payload = $this->sanitizeJson($row['payload_json'] ?? null);

                if ($dryRun) { continue; }

                $sql = 'INSERT INTO sales_orders (external_order_id, status, delivery_status, customer_name, customer_email, total_amount, currency, item_count, source, shipping_carrier, shipping_service, tracking_number, placed_at, payload_json)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                          status=?, delivery_status=?, customer_name=?, customer_email=?, total_amount=?, currency=?, item_count=?, source=?, shipping_carrier=?, shipping_service=?, tracking_number=?, placed_at=?, payload_json=?, updated_at=CURRENT_TIMESTAMP';

                $params = [
                    $extId, $status, $delivery, $custN, $custE, $total, $cur, $items, $source, $carrier, $service, $tracking, $placed, $payload,
                    $status, $delivery, $custN, $custE, $total, $cur, $items, $source, $carrier, $service, $tracking, $placed, $payload
                ];

                $affected = $this->db->executeStatement($sql, $params);
                if ($affected === 1) $inserted++;
                elseif ($affected >= 2) $updated++;
            }

            if (!$dryRun) { $this->db->commit(); }
            else { $this->db->rollBack(); }

        } catch (Throwable $e) {
            $this->db->rollBack();
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Commandes: vues=%d, insérées=%d, maj=%d, ignorées=%d%s',
            $seen, $inserted, $updated, $skipped, $dryRun ? ' (dry-run)' : ''
        ));

        return Command::SUCCESS;
    }

    /** @return Generator<int,array<string,string|null>> */
    private function iterateCsv(string $path, ?string $delimiter): Generator
    {
        $fh = new SplFileObject($path, 'r');
        $fh->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
        $head = $fh->fgets();
        if ($head == false) { return; }

        $delim = $delimiter ?: ($this->guessDelimiter($head));
        $headers = array_map('trim', str_getcsv($head, $delim));

        while (!$fh->eof()) {
            $line = $fh->fgets();
            if ($line == false || trim($line) === '') continue;
            $values = str_getcsv($line, $delim);
            $values = array_pad($values, count($headers), null);
            yield array_combine($headers, array_map(fn($v)=> $v===null?null:trim($v), $values));
        }
    }

    private function guessDelimiter(string $line): string
    {
        $c = substr_count($line, ','); $s = substr_count($line, ';');
        return $s > $c ? ';' : ',';
    }

    private function toDecimal(mixed $v): float
    {
        $s = str_replace([' ', "\u{00A0}"], '', (string)$v);
        $s = str_replace(',', '.', $s);
        return (float)$s;
    }

    private function toDateTimeNullable(?string $v): ?string
    {
        if (!$v) return null;
        $v = trim($v);
        // Accepte "YYYY-MM-DD HH:MM:SS" ou ISO 8601
        $ts = strtotime($v);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    private function sanitizeJson(?string $json): ?string
    {
        if ($json === null || trim($json) === '') return null;
        json_decode($json);
        if (json_last_error() === JSON_ERROR_NONE) return $json;
        // fallback: encapsuler en chaîne
        return json_encode(['raw' => $json], JSON_UNESCAPED_UNICODE);
    }
}


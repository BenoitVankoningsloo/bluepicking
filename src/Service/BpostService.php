<?php
declare(strict_types=1);

namespace App\Service;

use Bpost\BpostApiClient\Bpost;
use Bpost\BpostApiClient\Bpost\Order as BpostOrder;
use Bpost\BpostApiClient\Bpost\Order\Address;
use Bpost\BpostApiClient\Bpost\Order\Box;
use Bpost\BpostApiClient\Bpost\Order\Box\AtHome;
use Bpost\BpostApiClient\Bpost\Order\Receiver;
use Bpost\BpostApiClient\Bpost\Order\Sender;
use Bpost\BpostApiClient\Bpost\ProductConfiguration\Product;
use Doctrine\DBAL\Connection;
use ZipArchive;

final class BpostService
{
    public function __construct(private readonly Connection $db) {}

    private function api(): Bpost
    {
        $apiUrl = rtrim($_ENV['BP_API_BASE'] ?? 'https://shm-rest.bpost.cloud/services/shm/', '/') . '/';
        $id     = $_ENV['BP_ACCOUNT_ID'] ?? '';
        $pass   = $_ENV['BP_PASSPHRASE'] ?? '';
        if ($id === '' || $pass === '') {
            throw new \RuntimeException('bpost: identifiants manquants (Account ID / Passphrase)');
        }
        return new Bpost($id, $pass, $apiUrl);
    }

    private function optionalSenderFromEnv(): ?Sender
    {
        $name = trim((string)($_ENV['BP_SENDER_NAME'] ?? ''));
        if ($name === '') return null;

        $addr = new Address();
        $addr->setStreetName((string)($_ENV['BP_SENDER_STREET'] ?? ''));
        $addr->setNumber((string)($_ENV['BP_SENDER_NR'] ?? ''));
        $addr->setPostalCode((string)($_ENV['BP_SENDER_POSTCODE'] ?? ''));
        $addr->setLocality((string)($_ENV['BP_SENDER_LOCALITY'] ?? ''));
        $addr->setCountryCode((string)($_ENV['BP_SENDER_COUNTRY'] ?? 'BE'));

        $sender = new Sender();
        $sender->setAddress($addr);
        $sender->setName($name);
        if (!empty($_ENV['BP_SENDER_PHONE'])) $sender->setPhoneNumber((string)$_ENV['BP_SENDER_PHONE']);
        if (!empty($_ENV['BP_SENDER_EMAIL'])) $sender->setEmailAddress((string)$_ENV['BP_SENDER_EMAIL']);
        return $sender;
    }

    private function mapProduct(string $carrier, string $service): string
    {
        $def = strtoupper($_ENV['BP_DEFAULT_PRODUCT'] ?? 'BPACK_24H_PRO');
        $service = strtoupper($service);
        return match (true) {
            str_contains($service, '24H') && str_contains($service, 'BUSINESS') => Product::PRODUCT_NAME_BPACK_24H_BUSINESS,
            str_contains($service, '24H')                                          => Product::PRODUCT_NAME_BPACK_24H_PRO,
            str_contains($service, 'EUROPE')                                       => Product::PRODUCT_NAME_BPACK_EUROPE_BUSINESS,
            str_contains($service, 'WORLD')                                        => Product::PRODUCT_NAME_BPACK_WORLD_BUSINESS,
            default                                                                => (defined(Product::class.'::PRODUCT_NAME_'.$def) ? constant(Product::class.'::PRODUCT_NAME_'.$def) : Product::PRODUCT_NAME_BPACK_24H_PRO),
        };
    }

    /**
     * Crée l’expédition bpost, ouvre l’ordre et génère les étiquettes.
     * - Met à jour tracking + parcels en DB
     * - Retourne chemin ZIP + barcodes
     */
    public function createShipmentAndGetLabels(int $orderId): array
    {
        $api = $this->api();

        // 1) Charger la commande + meta
        $o = $this->db->fetchAssociative('SELECT * FROM sales_orders WHERE id = ?', [$orderId]);
        if (!$o) throw new \RuntimeException('Commande introuvable');
        $m = $this->db->fetchAssociative('SELECT * FROM sales_order_meta WHERE order_id = ?', [$orderId]) ?: [];

        // 2) Destinataire
        $recvAddr = new Address();
        $recvAddr->setStreetName((string)($m['ship_address1'] ?? ''));
        $recvAddr->setNumber((string)($m['ship_address2'] ?? ''));
        $recvAddr->setPostalCode((string)($m['ship_postcode'] ?? ''));
        $recvAddr->setLocality((string)($m['ship_city'] ?? ''));
        $recvAddr->setCountryCode((string)($m['ship_country'] ?? 'BE'));

        $receiver = new Receiver();
        $receiver->setAddress($recvAddr);
        $receiver->setName((string)($m['ship_name'] ?? $o['customer_name'] ?? ''));
        if (!empty($m['ship_phone']))     $receiver->setPhoneNumber((string)$m['ship_phone']);
        if (!empty($o['customer_email'])) $receiver->setEmailAddress((string)$o['customer_email']);

        // 3) Order + Box (Sender sur la Box, PAS sur Order)
        $ref   = (string)($o['external_order_id'] ?? $o['id']);
        $order = new BpostOrder($ref);

        $box = new Box();
        if ($sender = $this->optionalSenderFromEnv()) {
            // Certaines versions de la lib n’exposent pas setSender() sur Box ; on vérifie
            if (method_exists($box, 'setSender')) {
                $box->setSender($sender);
            }
        }

        $ath = new AtHome();
        $product = $this->mapProduct((string)($o['shipping_carrier'] ?? ''), (string)($o['shipping_service'] ?? ''));
        $ath->setProduct($product);
        $ath->setReceiver($receiver);
        $box->setNationalBox($ath);

        $order->addBox($box);

        // 4) Création / ouverture / labels
        $api->createOrReplaceOrder($order);                        // PENDING
        $api->modifyOrderStatus($ref, Box::BOX_STATUS_OPEN);       // OPEN

        $format = strtoupper($_ENV['BP_LABEL_FORMAT'] ?? 'A6');
        $asPdf  = strtoupper($_ENV['BP_LABEL_MIME'] ?? 'PDF') === 'PDF';

        $labels = $api->createLabelForOrder(
            $ref,
            $format === 'A4' ? Bpost::LABEL_FORMAT_A4 : Bpost::LABEL_FORMAT_A6,
            false,  // return
            $asPdf  // PDF ?
        );
        if (!$labels || count($labels) === 0) {
            throw new \RuntimeException('Aucune étiquette renvoyée par bpost');
        }

        // 5) Persist tracking + parcels
        $barcodes = [];
        $this->db->beginTransaction();
        try {
            $seq = 0;
            foreach ($labels as $label) {
                $seq++;
                $code = $label->getBarcode();
                $barcodes[] = $code;
                $this->db->executeStatement(
                    'INSERT INTO sales_order_parcels (order_id, seq, tracking_number, label_format)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE tracking_number = VALUES(tracking_number), label_format = VALUES(label_format)',
                    [$orderId, $seq, $code, $asPdf ? 'PDF' : 'PNG']
                );
            }
            $this->db->executeStatement(
                'UPDATE sales_orders SET tracking_number = ?, shipping_carrier = ?, shipping_service = ?, updated_at = NOW() WHERE id = ?',
                [$barcodes[0], 'bpost', $product, $orderId]
            );
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }

        // 6) ZIP des labels
        $dir = \dirname(__DIR__, 2).'/var/bpost';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $zipPath = $dir.'/labels_order_'.$orderId.'_'.date('Ymd_His').'.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le ZIP étiquettes');
        }
        foreach ($labels as $label) {
            $zip->addFromString($label->getBarcode().($asPdf ? '.pdf' : '.png'), $label->getBytes());
        }
        $zip->close();

        return ['zip_path' => $zipPath, 'barcodes' => $barcodes, 'count' => count($barcodes)];
    }

        public function getOrCreateLabelsBytes(int $orderId): array
{
    $api = $this->api();

    // 0) Base : commande + format voulu
    $o = $this->db->fetchAssociative('SELECT * FROM sales_orders WHERE id = ?', [$orderId]);
    if (!$o) { throw new \RuntimeException('Commande introuvable'); }

    $ref    = (string)($o['external_order_id'] ?? $o['id']);
    $format = strtoupper($_ENV['BP_LABEL_FORMAT'] ?? 'A6');
    $asPdf  = strtoupper($_ENV['BP_LABEL_MIME'] ?? 'PDF') === 'PDF';

    // 1) Tentative de reprint direct (sans recréer)
    $labels = [];
    try {
        $labels = $api->createLabelForOrder(
            $ref,
            $format === 'A4' ? \Bpost\BpostApiClient\Bpost::LABEL_FORMAT_A4 : \Bpost\BpostApiClient\Bpost::LABEL_FORMAT_A6,
            false, // not return labels for return shipment
            $asPdf // true => PDF, false => PNG
        );
    } catch (\Throwable $e) {
        // On ignore et on passera au fallback
        $labels = [];
    }

    if ($labels && \count($labels) > 0) {
        // Normalise (barcode + bytes)
        $out = [];
        foreach ($labels as $label) {
            $out[] = [
                'barcode' => $label->getBarcode(),
                'bytes'   => $label->getBytes(),
            ];
        }
        return [
            'mime'   => $asPdf ? 'application/pdf' : 'image/png',
            'labels' => $out,
        ];
    }

    // 2) Fallback idempotent : (re)créer + ouvrir + (re)générer via notre méthode, puis LIRE le ZIP
    $gen = $this->createShipmentAndGetLabels($orderId); // retourne ['zip_path', 'barcodes', 'count']
    $zipPath = $gen['zip_path'] ?? null;
    if (!$zipPath || !is_file($zipPath)) {
        throw new \RuntimeException('bpost: génération effectuée mais archive labels introuvable');
    }

    $za = new \ZipArchive();
    if ($za->open($zipPath) !== true) {
        throw new \RuntimeException('bpost: impossible d’ouvrir l’archive labels');
    }

    $labelsOut = [];
    $ext = $asPdf ? '.pdf' : '.png';
    for ($i = 0; $i < $za->numFiles; $i++) {
        $stat = $za->statIndex($i);
        if (!$stat) { continue; }
        $name  = $stat['name'];
        $bytes = $za->getFromIndex($i);
        if ($bytes === false) { continue; }

        // Barcode = nom de fichier sans extension
        $barcode = \preg_replace('/\.(pdf|png)$/i', '', (string) $name) ?: ('order-'.$orderId);
        $labelsOut[] = ['barcode' => $barcode, 'bytes' => $bytes];
    }
    $za->close();

    // (optionnel) supprimer le ZIP si tu ne veux rien laisser dans var/
    // @unlink($zipPath);

    if (!$labelsOut) {
        throw new \RuntimeException('bpost: impossible de récupérer/générer les étiquettes (zip vide)');
    }

    return [
        'mime'   => $asPdf ? 'application/pdf' : 'image/png',
        'labels' => $labelsOut,
    ];
}




}


<?php
/** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\BpostService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class OrderBpostController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly BpostService $bpost
    ) {}

    #[Route('/admin/orders/{id<\\d+>}/bpost/generate', name: 'admin_orders_bpost_generate', methods: ['POST'])]
    public function generate(int $id, Request $req): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('bpost_generate_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide.');
            return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
        }

        // Pré-validation adresse expédition (évite une erreur bpost peu claire)
        $meta = $this->db->fetchAssociative(
            'SELECT ship_name, ship_address1, ship_postcode, ship_city, ship_country FROM sales_order_meta WHERE order_id = ?',
            [$id]
        ) ?: [];
        $missing = [];
        if (trim((string)($meta['ship_address1'] ?? '')) === '') { $missing[] = 'rue'; }
        if (trim((string)($meta['ship_postcode'] ?? '')) === '') { $missing[] = 'code postal'; }
        if (trim((string)($meta['ship_city'] ?? '')) === '') { $missing[] = 'ville'; }
        if ($missing) {
            $this->addFlash('danger', 'Adresse d’expédition incomplète (champs obligatoires: '.implode(', ', $missing).').');
            return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
        }

        try {
            // === Appel tolérant : tente la méthode présente dans ton service ===
            $res = $this->callBpostServiceAdaptively($id, $usedMethod);

            // === Tracking ===
            $trk = $this->extractTracking($res);
            if ($trk) {
                $this->db->executeStatement(
                    'UPDATE sales_orders
                       SET tracking_number = ?, shipping_carrier = IFNULL(NULLIF(shipping_carrier,""), "bpost"),
                           updated_at = NOW()
                     WHERE id = ?',
                    [$trk, $id]
                );
            }

            // === Label (sauvegarde disque si présent) ===
            $labelBytes = $this->extractLabelPdf($res);
            if ($labelBytes !== null) {
                $ext  = $this->detectLabelExtension($res, $labelBytes); // 'pdf' ou 'png'
                $path = $this->labelFilePath($id, $ext);
                @mkdir(\dirname($path), 0775, true);
                file_put_contents($path, $labelBytes);

                // log côté meta (optionnel)
                $this->db->executeStatement(
                    'INSERT INTO sales_order_meta (order_id, labels_last_printed_at, labels_print_count)
                     VALUES (?, NOW(), 1)
                     ON DUPLICATE KEY UPDATE
                        labels_last_printed_at = NOW(),
                        labels_print_count = IFNULL(labels_print_count,0) + 1',
                    [$id]
                );
            }

            // === Feedback utilisateur ===
            if ($trk && $labelBytes !== null) {
                $this->addFlash('success', sprintf('bpost (%s): expédition créée · tracking %s · label enregistré.', $usedMethod, $trk));
            } elseif ($trk) {
                $this->addFlash('warning', sprintf('bpost (%s): expédition créée · tracking %s · pas d’étiquette exploitable renvoyée.', $usedMethod, $trk));
            } else {
                $this->addFlash('warning', sprintf('bpost (%s): expédition créée mais aucun tracking détecté.', $usedMethod));
            }

        } catch (\Throwable $e) {
            $this->addFlash('danger', $this->humanizeBpostError($e->getMessage()));
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }

    // ---------- Helpers ----------

    /** Essaie createShipment(), generateForOrder(), createShipmentAndGetLabels(), etc. */
    private function callBpostServiceAdaptively(int $orderId, ?string &$calledFn = null): mixed
    {
        $candidates = [
            // Préfère un retour direct des bytes (plus simple pour sauvegarder un label)
            'getOrCreateLabelsBytes',
            'createShipment',
            'createShipmentAndGetLabels',
            'generateForOrder',
            'createForOrder',
            'shipOrder',
            'submitShipmentForOrder',
            'createShipmentForOrder',
            'generateShipment',
        ];

        foreach ($candidates as $fn) {
            if (method_exists($this->bpost, $fn)) {
                $calledFn = $fn;
                return $this->bpost->{$fn}($orderId);
            }
        }

        // Auto-détection regex
        $methods = get_class_methods($this->bpost) ?: [];
        foreach ($methods as $m) {
            if (preg_match('/(create|generate|ship|submit).*(shipment|label|order)/i', $m)) {
                try {
                    $ref = new \ReflectionMethod($this->bpost, $m);
                    if ($ref->getNumberOfRequiredParameters() <= 1) {
                        $calledFn = $m;
                        return $this->bpost->{$m}($orderId);
                    }
                } catch (\Throwable) {}
            }
        }

        throw new \RuntimeException(
            'BpostService: aucune méthode compatible trouvée pour créer l’expédition.'
        );
    }

    /** Extrait le tracking depuis un retour hétérogène */
    private function extractTracking(mixed $res): ?string
    {
        if (is_string($res)) { return $res; }
        if (!is_array($res)) { return null; }

        return $res['tracking']
            ?? $res['tracking_number']
            ?? $res['barcode']
            ?? $res['parcel_barcode']
            ?? ($res['parcels'][0]['barcode'] ?? null)
            ?? ($res['labels'][0]['barcode'] ?? null)
            ?? ($res['barcodes'][0] ?? null)
            ?? null;
    }

    /** Extrait le PDF (binaire) depuis un retour hétérogène (array) ou null si absent */
    private function extractLabelPdf(mixed $res): ?string
    {
        if (!is_array($res)) return null;

        // 0) Si un ZIP a été généré, prendre le premier fichier de l’archive
        if (!empty($res['zip_path']) && is_string($res['zip_path']) && is_file($res['zip_path'])) {
            $za = new \ZipArchive();
            if ($za->open($res['zip_path']) === true) {
                if ($za->numFiles > 0) {
                    $bytes = $za->getFromIndex(0);
                    $za->close();
                    if ($bytes !== false) {
                        return $bytes;
                    }
                }
                $za->close();
            }
        }

        // cas "labels" = [ { pdf: base64|binaire|url?, content:..., mime: application/pdf }, ... ]
        $cands = [];
        if (isset($res['labels']) && is_array($res['labels'])) {
            foreach ($res['labels'] as $lab) {
                if (is_array($lab)) $cands[] = $lab;
            }
        } else {
            // parfois sous d’autres clés
            foreach (['label','label_pdf','pdf','document'] as $k) {
                if (isset($res[$k]) && is_array($res[$k])) $cands[] = $res[$k];
            }
        }

        foreach ($cands as $lab) {
            // 0) bytes bruts (cas getOrCreateLabelsBytes)
            if (!empty($lab['bytes']) && is_string($lab['bytes'])) {
                return $lab['bytes'];
            }
            // 1) contenu direct binaire
            if (!empty($lab['binary']) && is_string($lab['binary'])) {
                return $lab['binary'];
            }
            // 2) base64
            foreach (['content','pdf','data','base64'] as $k) {
                if (!empty($lab[$k]) && is_string($lab[$k])) {
                    $bin = base64_decode($lab[$k], true);
                    if ($bin !== false) return $bin;
                }
            }
            // 3) parfois un champ unique 'label_pdf' base64
            if (!empty($res['label_pdf']) && is_string($res['label_pdf'])) {
                $bin = base64_decode($res['label_pdf'], true);
                if ($bin !== false) return $bin;
            }
            // 4) url directe NON gérée ici (on évite un fetch réseau côté serveur)
        }

        return null;
    }

        /** Chemin de stockage local du label (pas besoin de DB) */
        private function labelFilePath(int $orderId, string $ext = 'pdf'): string
        {
            $ext = ($ext === 'png') ? 'png' : 'pdf';
            return \sprintf('%s/bpost/labels/order_%d.%s', \rtrim(\dirname(__DIR__, 3).'/var', '/'), $orderId, $ext);
            // Ex.: /var/www/hello-crud/var/bpost/labels/order_154.pdf
        }

        /** Détermine l’extension du label via le mime déclaré ou la signature magique des bytes */
        private function detectLabelExtension(mixed $res, string $bytes): string
        {
            // 1) Si la structure indique le MIME, s’y fier
            if (is_array($res) && isset($res['mime']) && is_string($res['mime'])) {
                $mime = strtolower($res['mime']);
                if (str_contains($mime, 'pdf')) return 'pdf';
                if (str_contains($mime, 'png')) return 'png';
            }

            // 2) Signatures magiques
            if (str_starts_with($bytes, '%PDF-')) {
                return 'pdf';
            }
            if (\strncmp($bytes, "\x89PNG", 4) === 0) {
                return 'png';
            }

            // 3) Défaut
            return 'pdf';
    }

    /** Convertit certains messages techniques bpost en messages utilisateurs */
    private function humanizeBpostError(string $raw): string
    {
        $r = strtolower($raw);

        // Rue manquante / invalide
        if (str_contains($r, 'streetnametype') || (str_contains($r, 'cvc-pattern-valid') && str_contains($r, 'street'))) {
            return 'Adresse d’expédition incomplète (rue obligatoire).';
        }

        // Code postal
        if (str_contains($r, 'postal') || str_contains($r, 'postalcode') || str_contains($r, 'zip')) {
            return 'Adresse d’expédition incomplète (code postal invalide ou manquant).';
        }

        // Ville
        if (str_contains($r, 'locality') || str_contains($r, 'city')) {
            return 'Adresse d’expédition incomplète (ville obligatoire).';
        }

        // Fallback
        return 'bpost: '.$raw;
    }
}


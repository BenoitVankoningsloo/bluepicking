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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('bpost_generate_'.$id, (string)$req->request->get('_token'))) {
            $this->addFlash('danger', 'CSRF invalide.');
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

            // === Label PDF (sauvegarde disque si présent) ===
            $pdf = $this->extractLabelPdf($res);
            if ($pdf !== null) {
                $path = $this->labelFilePath($id);
                @mkdir(\dirname($path), 0775, true);
                file_put_contents($path, $pdf);

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
            if ($trk && $pdf !== null) {
                $this->addFlash('success', sprintf('bpost (%s): expédition créée · tracking %s · label enregistré.', $usedMethod, $trk));
            } elseif ($trk) {
                $this->addFlash('warning', sprintf('bpost (%s): expédition créée · tracking %s · pas de PDF renvoyé.', $usedMethod, $trk));
            } else {
                $this->addFlash('warning', sprintf('bpost (%s): expédition créée mais aucun tracking détecté.', $usedMethod));
            }

        } catch (\Throwable $e) {
            $this->addFlash('danger', 'bpost: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_process', ['id' => $id]);
    }

    // ---------- Helpers ----------

    /** Essaie createShipment(), generateForOrder(), createShipmentAndGetLabels(), etc. */
    private function callBpostServiceAdaptively(int $orderId, ?string &$calledFn = null): mixed
    {
        $candidates = [
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

    /** Chemin de stockage local du PDF label (pas besoin de DB) */
    private function labelFilePath(int $orderId): string
    {
        return \sprintf('%s/bpost/labels/order_%d.pdf', \rtrim(\dirname(__DIR__, 3).'/var', '/'), $orderId);
        // Ex.: /var/www/hello-crud/var/bpost/labels/order_154.pdf
    }
}


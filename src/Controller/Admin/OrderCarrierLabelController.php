<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\BpostService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class OrderCarrierLabelController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly BpostService $bpost
    ) {}

    #[Route('/admin/orders/{id<\\d+>}/carrier-label.pdf', name: 'admin_orders_carrier_label_pdf', methods: ['GET'])]
    public function __invoke(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // 1) Si on a déjà sauvegardé un PDF local => on le sert
        $path = $this->labelFilePath($id);
        if (is_readable($path) && filesize($path) > 0) {
            return $this->binaryPdf($path, 'carrier_label_'.$id.'.pdf');
        }

        // 2) Sinon on tente de (re)demander au service Bpost une récupération de label
        $pdf = $this->fetchLabelPdfAdaptively($id);
        if ($pdf === null) {
            // message clair
            throw new \RuntimeException('bpost: impossible de récupérer/générer le label (aucun PDF disponible).');
        }

        @mkdir(\dirname($path), 0775, true);
        file_put_contents($path, $pdf);

        // log côté meta
        $this->db->executeStatement(
            'INSERT INTO sales_order_meta (order_id, labels_last_printed_at, labels_print_count)
             VALUES (?, NOW(), 1)
             ON DUPLICATE KEY UPDATE
               labels_last_printed_at = NOW(),
               labels_print_count = IFNULL(labels_print_count,0) + 1',
            [$id]
        );

        return $this->binaryPdf($path, 'carrier_label_'.$id.'.pdf');
    }

    // ------- helpers -------

    private function fetchLabelPdfAdaptively(int $orderId): ?string
    {
        // Recherche de méthodes candidates côté service
        $candidates = [
            'getOrCreateLabelsBytes',
            'getLabelsForOrder',
            'getLabelForOrder',
            'downloadLabel',
            'downloadLabels',
            'fetchLabel',
            'fetchLabels',
            'getShipmentLabels',
            'getShipmentLabel',
        ];
        foreach ($candidates as $fn) {
            if (method_exists($this->bpost, $fn)) {
                try {
                    $res = $this->bpost->{$fn}($orderId);
                    $pdf = $this->extractLabelPdf($res);
                    if ($pdf !== null) return $pdf;
                } catch (\Throwable) {}
            }
        }

        // Si rien, tenter un retry sur la création (certaines API renvoient aussi les labels)
        $methods = get_class_methods($this->bpost) ?: [];
        foreach ($methods as $m) {
            if (preg_match('/(create|generate).*(shipment|label|order)/i', $m)) {
                try {
                    $res = $this->bpost->{$m}($orderId);
                    $pdf = $this->extractLabelPdf($res);
                    if ($pdf !== null) return $pdf;
                } catch (\Throwable) {}
            }
        }

        return null;
    }

    private function extractLabelPdf(mixed $res): ?string
    {
        if (!is_array($res)) return null;

        $cands = [];
        if (isset($res['labels']) && is_array($res['labels'])) {
            foreach ($res['labels'] as $lab) if (is_array($lab)) $cands[] = $lab;
        } else {
            foreach (['label','label_pdf','pdf','document'] as $k) {
                if (isset($res[$k]) && is_array($res[$k])) $cands[] = $res[$k];
            }
        }

        foreach ($cands as $lab) {
            if (!empty($lab['bytes']) && is_string($lab['bytes'])) {
                return $lab['bytes'];
            }
            if (!empty($lab['binary']) && is_string($lab['binary'])) {
                return $lab['binary'];
            }
            foreach (['content','pdf','data','base64'] as $k) {
                if (!empty($lab[$k]) && is_string($lab[$k])) {
                    $bin = base64_decode($lab[$k], true);
                    if ($bin !== false) return $bin;
                }
            }
            if (!empty($res['label_pdf']) && is_string($res['label_pdf'])) {
                $bin = base64_decode($res['label_pdf'], true);
                if ($bin !== false) return $bin;
            }
        }

        return null;
    }

    private function binaryPdf(string $path, string $filename): BinaryFileResponse
    {
        $resp = new BinaryFileResponse($path);
        $resp->headers->set('Content-Type', 'application/pdf');
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);
        return $resp;
    }

    private function labelFilePath(int $orderId): string
    {
        return \sprintf('%s/bpost/labels/order_%d.pdf', \rtrim(\dirname(__DIR__, 3).'/var', '/'), $orderId);
    }
}


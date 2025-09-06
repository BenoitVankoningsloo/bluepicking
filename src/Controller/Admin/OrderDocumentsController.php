<?php /** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\PdfService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderDocumentsController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly PdfService $pdf
    ) {}

    private function loadOrderFull(int $id): array
    {
        $o = $this->db->fetchAssociative('SELECT * FROM sales_orders WHERE id = ?', [$id]);
        if (!$o) { throw $this->createNotFoundException('Commande introuvable'); }

        $meta    = $this->db->fetchAssociative('SELECT * FROM sales_order_meta WHERE order_id = ?', [$id]) ?: [];
        $lines   = $this->db->fetchAllAssociative('SELECT * FROM sales_order_lines WHERE order_id = ? ORDER BY id ASC', [$id]);
        $parcels = $this->db->fetchAllAssociative('SELECT * FROM sales_order_parcels WHERE order_id = ? ORDER BY seq ASC', [$id]);

        $carrier = $meta['shipping_carrier_override'] ?? $o['shipping_carrier'] ?? '';
        $service = $meta['shipping_service_override'] ?? $o['shipping_service'] ?? '';

        return compact('o','meta','lines','parcels','carrier','service');
    }

    #[Route('/admin/orders/{id<\d+>}/picking.pdf', name: 'admin_orders_picking_pdf', methods: ['GET'])]
    public function pickingPdf(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $html = $this->renderView('admin/orders/pdf/picking.html.twig', $this->loadOrderFull($id));
        return $this->pdf->render($html, 'picking_'.$id.'_'.date('Ymd_His').'.pdf');
    }

    #[Route('/admin/orders/{id<\d+>}/delivery-note.pdf', name: 'admin_orders_delivery_pdf', methods: ['GET'])]
    public function deliveryPdf(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $html = $this->renderView('admin/orders/pdf/delivery.html.twig', $this->loadOrderFull($id));
        return $this->pdf->render($html, 'delivery_'.$id.'_'.date('Ymd_His').'.pdf');
    }

    #[Route('/admin/orders/{id<\d+>}/labels.pdf', name: 'admin_orders_labels_pdf', methods: ['GET'])]
    public function labelsPdf(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $html = $this->renderView('admin/orders/pdf/labels.html.twig', $this->loadOrderFull($id));
        return $this->pdf->render($html, 'labels_'.$id.'_'.date('Ymd_His').'.pdf', paper: 'A5');
    }

    // Optionnel : ZPL (si tu veux le garder)
    #[Route('/admin/orders/{id<\d+>}/labels.zpl', name: 'admin_orders_labels_zpl', methods: ['GET'])]
    public function labelsZpl(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $data = $this->loadOrderFull($id);
        $meta = $data['meta']; $o = $data['o'];

        $toLine = function (?string $s): string {
            $s = trim((string)$s);
            return strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        };
        $name  = $toLine($meta['ship_name'] ?: $o['customer_name'] ?? '');
        $addr1 = $toLine($meta['ship_address1'] ?? '');
        $addr2 = $toLine($meta['ship_address2'] ?? '');
        $city  = $toLine(($meta['ship_postcode'] ?? '').' '.($meta['ship_city'] ?? ''));
        $ctry  = $toLine($meta['ship_country'] ?? '');

        $parcels = $data['parcels'];
        if (!$parcels) {
            $count = max(1, (int)($meta['parcels_count'] ?? 1));
            for ($i = 1; $i <= $count; $i++) {
                $parcels[] = ['seq' => $i, 'tracking_number' => $o['tracking_number'] ?? ''];
            }
        }

        $zpl = "^XA\n^PW812\n^LL1218\n";
        foreach ($parcels as $p) {
            $seq = (int) $p['seq'];
            $zpl .= "^FO30,30^A0N,40,40^FDbluepicking^FS\n";
            $zpl .= "^FO30,80^A0N,35,35^FD{$name}^FS\n";
            if ($addr1) $zpl .= "^FO30,120^A0N,35,35^FD{$addr1}^FS\n";
            if ($addr2) $zpl .= "^FO30,160^A0N,35,35^FD{$addr2}^FS\n";
            if ($city || $ctry) $zpl .= "^FO30,200^A0N,35,35^FD{$city} {$ctry}^FS\n";
            $trk = $toLine($p['tracking_number'] ?? '');
            if ($trk !== '') {
                $zpl .= "^FO30,260^BY3\n^BCN,100,Y,N,N\n^FD{$trk}^FS\n";
            }
            $label = "Colis {$seq}/" . (int) ($meta['parcels_count'] ?? count($parcels)) . " · Cmd " . ($o['external_order_id'] ?? $o['id']);
            $zpl .= "^FO30,380^A0N,30,30^FD{$label}^FS\n";
            $zpl .= "^XZ\n^XA\n";
        }
        $zpl .= "^XZ\n";

        return new Response($zpl, 200, [
            'Content-Type'        => 'application/zpl', // ou 'text/plain; charset=UTF-8'
            'Content-Disposition' => sprintf('attachment; filename="labels_%d.zpl"', $id),
        ]);
    }
}


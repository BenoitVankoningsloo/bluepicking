<?php /** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSalesService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeliveryOrderShowController extends AbstractController
{
    public function __construct(private readonly OdooSalesService $sales, private readonly Connection $db) {}

    #[Route('/admin/deliveries/{id}', name: 'admin_delivery_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $data = $this->sales->getPickingWithMoves($id);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Odoo: '.$e->getMessage());
            return $this->redirectToRoute('admin_deliveries_list', $request->query->all());
        }

        // Lien vers la commande locale (si trouvée par origin)
        $linkedOrder = null;
        $origin = (string)($data['picking']['origin'] ?? '');
        if ($origin !== '') {
            $row = $this->db->fetchAssociative(
                'SELECT id, odoo_name, external_order_id FROM sales_orders WHERE odoo_name = ? OR external_order_id = ? LIMIT 1',
                [$origin, $origin]
            );
            if ($row) {
                $linkedOrder = [
                    'id'  => (int)$row['id'],
                    'ref' => (string)($row['odoo_name'] ?: $row['external_order_id'] ?: $origin),
                ];
            }
        }

        return $this->render('admin/deliveries/show.html.twig', [
            'picking'      => $data['picking'],
            'lines'        => $data['lines'],
            'linked_order' => $linkedOrder,
        ]);
    }

    #[Route('/admin/deliveries/{id}/save', name: 'admin_delivery_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function savePicked(int $id, Request $request): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $picked = (array) $request->request->all('picked');
        $clean = [];
        foreach ($picked as $pid => $qty) {
            if (!is_numeric($pid)) { continue; }
            $clean[(int)$pid] = (float)$qty;
        }

        try {
            $this->sales->setPickedQuantities($id, $clean);
            $this->addFlash('success', 'Quantités enregistrées.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Échec enregistrement: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_delivery_show', ['id' => $id]);
    }

    #[Route('/admin/deliveries/{id}/validate', name: 'admin_delivery_validate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validatePicking(int $id, Request $request): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

            $qtys  = (array) $request->request->all('qty');    // quantités à appliquer
            $clean = [];
            foreach ($qtys as $pid => $q) {
                if (!is_numeric($pid)) { continue; }
                $q = (float)$q;
                if ($q > 0) {
                    $clean[(int)$pid] = $q;
                }
        }

        try {
            $this->sales->setPickedAndValidatePicking($id, $clean);
            $this->addFlash('success', 'Picking validé avec succès.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Échec validation: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_delivery_show', ['id' => $id]);
    }
}

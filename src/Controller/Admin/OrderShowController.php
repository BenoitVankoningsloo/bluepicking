<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderShowController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @throws Exception
     */
    #[Route('/admin/orders/{id<\\d+>}', name: 'admin_orders_show', methods: ['GET'])]
    public function __invoke(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $o = $this->db->fetchAssociative('SELECT * FROM sales_orders WHERE id=?', [$id]);
        if (!$o) {
            throw $this->createNotFoundException('Commande introuvable');
        }

        $meta  = $this->db->fetchAssociative('SELECT * FROM sales_order_meta WHERE order_id=?', [$id]) ?: [];
        $lines = $this->db->fetchAllAssociative('SELECT * FROM sales_order_lines WHERE order_id=? ORDER BY id ASC', [$id]);

        // Fallback adresse depuis le payload Odoo (décodage côté PHP, pas de json_decode dans Twig)
        $partner = null;
        if (!empty($o['payload_json'])) {
            $payload = json_decode((string)$o['payload_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $partner = $payload['partner'] ?? null;
                // Normalise la country_code si res.country a été injecté côté sync
                if (!empty($partner['country_id']) && is_array($partner['country_id'])) {
                    // si tu stockes aussi country_code dans le payload, tu peux l’utiliser directement
                    $partner['country_code'] = $partner['country_code'] ?? null;
                }
            }
        }

        return $this->render('admin/orders/show.html.twig', [
            'o'       => $o,
            'meta'    => $meta,
            'lines'   => $lines,
            'partner' => $partner, // <- utilisé en fallback dans le template
        ]);
    }
}


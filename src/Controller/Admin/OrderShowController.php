<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use function mb_strtolower;
use function str_replace;
use function strstr;
use function trim;
use function ucwords;

final class OrderShowController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @throws Exception
     */
    #[Route('/admin/orders/{id<\\d+>}', name: 'admin_orders_show', methods: ['GET'])]
    public function __invoke(int $id): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

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

        // Résoudre le nom du préparateur (email -> label lisible)
        $preparedByEmail = mb_strtolower(trim((string)($meta['prepared_by'] ?? '')));
        $preparedByLabel = $preparedByEmail;
        if ($preparedByEmail !== '') {
            try {
                $row = $this->db->fetchAssociative(
                    "SELECT COALESCE(NULLIF(TRIM(name), ''), email) AS label
                       FROM users
                      WHERE LOWER(email) = LOWER(?)",
                    [$preparedByEmail]
                );
                if (!$row) {
                    $row = $this->db->fetchAssociative(
                        "SELECT COALESCE(NULLIF(TRIM(name), ''), email) AS label
                           FROM \"user\"
                          WHERE LOWER(email) = LOWER(?)",
                        [$preparedByEmail]
                    );
                }
                if ($row && !empty($row['label'])) {
                    $preparedByLabel = (string) $row['label'];
                } else {
                    // Fallback lisible depuis la partie locale de l'email
                    $local = (string) strstr($preparedByEmail, '@', true);
                    $preparedByLabel = ucwords(str_replace(['.', '_', '-'], ' ', $local !== '' ? $local : $preparedByEmail));
                }
            } catch (Throwable) {
                $local = (string) strstr($preparedByEmail, '@', true);
                $preparedByLabel = ucwords(str_replace(['.', '_', '-'], ' ', $local !== '' ? $local : $preparedByEmail));
            }
        }

        // Pickings liés (via origin = odoo_name ou external_order_id)
        $pickings = [];
        try {
            // Vérifie existence table
            $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne();
            $originRef = (string)($o['odoo_name'] ?? '');
            if ($originRef === '' && !empty($o['external_order_id'])) {
                $originRef = (string)$o['external_order_id'];
            }
            if ($originRef !== '') {
                $pickings = $this->db->fetchAllAssociative(
                    'SELECT odoo_id, name, state, scheduled_date FROM odoo_pickings WHERE origin = ? ORDER BY id ASC',
                    [$originRef]
                );
            }
        } catch (Throwable) {
            $pickings = [];
        }

        return $this->render('admin/orders/show.html.twig', [
            'o'                 => $o,
            'meta'              => $meta,
            'lines'             => $lines,
            'partner'           => $partner, // <- utilisé en fallback dans le template
            'prepared_by_label' => $preparedByLabel,
            'pickings'          => $pickings,
        ]);
    }
}


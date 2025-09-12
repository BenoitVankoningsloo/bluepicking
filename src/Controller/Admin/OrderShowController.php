<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSalesService;
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
    public function __invoke(int $id, OdooSalesService $odooSvc): Response
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

        // Pickings liés (via origin = odoo_name ou external_order_id), avec fallback live depuis Odoo
        $pickings = [];
        // 1) Lecture locale (si la table existe)
        try {
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

        // 2) Complément via Odoo (comme la page de préparation) pour garantir l’affichage
        try {
            $byId = [];
            foreach ($pickings as $p) {
                $oid = isset($p['odoo_id']) ? (int)$p['odoo_id'] : 0;
                if ($oid > 0) { $byId[$oid] = true; }
            }

            // Référence SO: id Odoo si dispo, sinon name, sinon external_order_id
            $soRef = null;
            if (!empty($o['odoo_sale_order_id'])) {
                $soRef = (int)$o['odoo_sale_order_id'];
            } elseif (!empty($o['odoo_name'])) {
                $soRef = (string)$o['odoo_name'];
            } elseif (!empty($o['external_order_id'])) {
                $soRef = (string)$o['external_order_id'];
            }

            // a) Via sale.order (picking_ids) si possible
            if ($soRef !== null && $soRef !== '') {
                try {
                    $soData = $odooSvc->fetchSaleOrder($soRef); // retourne pickings avec id/name/state/scheduled_date le cas échéant
                    $soPickings = (array)($soData['pickings'] ?? []);
                    foreach ($soPickings as $pk) {
                        $pid = (int)($pk['id'] ?? 0);
                        if ($pid <= 0 || isset($byId[$pid])) { continue; }
                        $pickings[] = [
                            'odoo_id'        => $pid,
                            'name'           => (string)($pk['name'] ?? ($pid ?: '')),
                            'state'          => (string)($pk['state'] ?? ''),
                            'scheduled_date' => $pk['scheduled_date'] ?? null,
                        ];
                        $byId[$pid] = true;
                    }
                } catch (Throwable) {
                    // on tente le fallback par origin
                }
            }

            // b) Fallback par origin (listPickings)
            $origin = (string)($o['odoo_name'] ?? ($o['external_order_id'] ?? ''));
            if ($origin !== '') {
                $rows = $odooSvc->listPickings([['origin', '=', $origin]], 20, 0, ['id','name','state','scheduled_date','origin']);
                foreach ($rows as $rowPk) {
                    $pid = (int)($rowPk['id'] ?? 0);
                    if ($pid <= 0 || isset($byId[$pid])) { continue; }
                    $pickings[] = [
                        'odoo_id'        => $pid,
                        'name'           => (string)($rowPk['name'] ?? ($pid ?: '')),
                        'state'          => (string)($rowPk['state'] ?? ''),
                        'scheduled_date' => $rowPk['scheduled_date'] ?? null,
                    ];
                    $byId[$pid] = true;
                }
            }
        } catch (Throwable) {
            // silencieux: on garde au moins les données locales si disponibles
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


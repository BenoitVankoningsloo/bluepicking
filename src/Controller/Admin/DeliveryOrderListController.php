<?php /** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\OdooSyncService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeliveryOrderListController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/deliveries', name: 'admin_deliveries_list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Autoriser ADMIN ou PREPARATEUR
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $q     = \trim((string) $request->query->get('q', ''));
        $state = \trim((string) $request->query->get('state', ''));
        $from  = \trim((string) $request->query->get('scheduled_from', '')); // YYYY-MM-DD
        $to    = \trim((string) $request->query->get('scheduled_to', ''));   // YYYY-MM-DD

        $page     = \max(1, (int) $request->query->get('page', 1));
        $perPage  = (int) $request->query->get('per_page', 25);
        $perPage  = \min(\max($perPage, 5), 200);

        $sort = (string) $request->query->get('sort', 'scheduled_date');
        $dir  = \strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $sortable = [
            'name'           => 'p.name',
            'origin'         => 'p.origin',
            'partner_name'   => 'p.partner_name',
            'scheduled_date' => 'p.scheduled_date',
            'state'          => 'p.state',
            'updated_at'     => 'p.updated_at',
        ];
        $orderBy = $sortable[$sort] ?? 'p.scheduled_date';

        // Lecture DB si la table odoo_pickings existe, sinon affiche vide
        $items = [];
        $total = 0;
        try {
            // Option: vérifier l’existence de la table (portable)
            $exists = true;
            try {
                $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne();
            } catch (\Throwable) {
                $exists = false;
            }

            if ($exists) {
                $qb = $this->db->createQueryBuilder()
                    ->from('odoo_pickings', 'p');

                if ($q !== '') {
                    $qb->andWhere('(p.name LIKE :q OR p.origin LIKE :q OR p.partner_name LIKE :q)')
                       ->setParameter('q', '%'.$q.'%');
                }
                if ($state !== '') {
                    $qb->andWhere('p.state = :st')->setParameter('st', $state);
                }
                if ($from !== '') {
                    $qb->andWhere('p.scheduled_date >= :from')->setParameter('from', $from . ' 00:00:00');
                }
                if ($to !== '') {
                    $qb->andWhere('p.scheduled_date <= :to')->setParameter('to', $to . ' 23:59:59');
                }

                // Total
                $countQb = clone $qb;
                $countQb->select('COUNT(*) AS cnt');
                $total = (int) $this->db->fetchOne($countQb->getSQL(), $countQb->getParameters());

                // Page
                $qb->select('p.*')
                   ->orderBy($orderBy, $dir)
                   ->setFirstResult(($page - 1) * $perPage)
                   ->setMaxResults($perPage);

                $items = $this->db->fetchAllAssociative($qb->getSQL(), $qb->getParameters());

                // Enrichissement: retrouver order_id à partir de origin en une seule requête
                if ($items) {
                    $origins = array_values(array_unique(array_filter(array_map(
                        static fn(array $r): string => (string)($r['origin'] ?? ''),
                        $items
                    ), static fn(string $s): bool => $s !== '')));
                    if ($origins) {
                        // Prépare IN (?, ?, ...) pour les deux colonnes
                        $placeholders = implode(',', array_fill(0, count($origins), '?'));
                        $sql = 'SELECT id, odoo_name, external_order_id
                                  FROM sales_orders
                                 WHERE odoo_name IN (' . $placeholders . ')
                                    OR external_order_id IN (' . $placeholders . ')';
                        $rows = $this->db->fetchAllAssociative($sql, array_merge($origins, $origins));

                        // Mapping origin -> order_id
                        $map = [];
                        foreach ($rows as $r) {
                            if (!empty($r['odoo_name']))         { $map[(string)$r['odoo_name']] = (int)$r['id']; }
                            if (!empty($r['external_order_id'])) { $map[(string)$r['external_order_id']] = (int)$r['id']; }
                        }

                        // Injecte order_id pour le template
                        foreach ($items as &$it) {
                            $o = (string)($it['origin'] ?? '');
                            $it['order_id'] = $o !== '' && isset($map[$o]) ? $map[$o] : null;
                        }
                        unset($it);
                    }
                }
            }
        } catch (Exception) {
            // silencieux: on affiche simplement vide
            $items = [];
            $total = 0;
        }

        $totalPages = \max(1, (int) \ceil($total / $perPage));
        $page = \min($page, $totalPages);

        return $this->render('admin/deliveries/index.html.twig', [
            'items'          => $items,
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $perPage,
            'totalPages'     => $totalPages,
            'q'              => $q,
            'state'          => $state,
            'scheduled_from' => $from,
            'scheduled_to'   => $to,
            'sort'           => $sort,
            'dir'            => \strtolower($dir),
            'table_exists'   => !empty($exists),
        ]);
    }

    #[Route('/admin/deliveries/odoo/import', name: 'admin_deliveries_import', methods: ['GET'])]
    public function import(Request $request, OdooSyncService $sync): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $statesCsv = (string) $request->query->get('states', 'waiting,confirmed,assigned,done');
        $states    = \array_values(\array_filter(\array_map('trim', \explode(',', $statesCsv))));
        $from      = (string) $request->query->get('from', '');
        $to        = (string) $request->query->get('to', '');
        $limit     = \max(1, (int) $request->query->get('limit', 500));
        $offset    = \max(0, (int) $request->query->get('offset', 0));

        try {
            $res = $this->callOdooPickingsAdaptively($sync, $states, $from ?: null, $to ?: null, $limit, $offset);
            $imported = (int) ($res['imported'] ?? 0);
            $this->addFlash('success', sprintf('Import Odoo (pickings): %d éléments importés.', $imported));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Odoo (pickings): '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_deliveries_list', $request->query->all());
    }

    /**
     * Essaie d’appeler une méthode de synchro des pickings présente dans OdooSyncService.
     * Signatures supportées (adaptatives):
     *  - syncPickings(array $states, ?string $since, ?string $until, int $limit=500, int $offset=0)
     *  - syncStockPickings(...), syncInventoryPickings(...), syncOdooPickings(...), syncPickingsBatch(...)
     */
    private function callOdooPickingsAdaptively(
        OdooSyncService $svc,
        array $states,
        ?string $since,
        ?string $until,
        int $limit,
        int $offset
    ): array {
        $candidates = [
            'syncPickings',
            'syncStockPickings',
            'syncInventoryPickings',
            'syncOdooPickings',
            'syncPickingsBatch',
            'pickings', // alias éventuel
        ];

        $lastError = null;
        $foundAny  = false;

        foreach ($candidates as $fn) {
            if (\method_exists($svc, $fn)) {
                $foundAny = true;
                // tente avec (states, since, until, limit, offset) puis variantes réduites
                try {
                    $ref = new \ReflectionMethod($svc, $fn);
                    $n   = $ref->getNumberOfParameters();
                    if ($n >= 5) return (array) $svc->{$fn}($states, $since, $until, $limit, $offset);
                    if ($n === 4) return (array) $svc->{$fn}($states, $since, $until, $limit);
                    if ($n === 3) return (array) $svc->{$fn}($states, $since, $until);
                    if ($n === 2) return (array) $svc->{$fn}($states, $since);
                    if ($n === 1) return (array) $svc->{$fn}($states);
                    return (array) $svc->{$fn}();
                } catch (\Throwable $e) {
                    $lastError = $e; // on mémorise l’erreur et on essaie le suivant
                }
            }
        }

        if ($foundAny && $lastError !== null) {
            throw $lastError; // remonte la vraie cause de l’échec (ex: auth Odoo manquante)
        }

        throw new \RuntimeException("Aucune méthode de synchro 'pickings' trouvée dans OdooSyncService.");
    }
}

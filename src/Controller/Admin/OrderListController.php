<?php /** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class OrderListController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/orders', name: 'admin_orders_list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $q        = trim((string) $request->query->get('q', ''));
        $status   = trim((string) $request->query->get('status', ''));
        $source   = trim((string) $request->query->get('source', ''));
        $from     = trim((string) $request->query->get('placed_from', '')); // YYYY-MM-DD
        $to       = trim((string) $request->query->get('placed_to', ''));   // YYYY-MM-DD

        // Nouveaux filtres
        $preparedBy    = trim((string) $request->query->get('prepared_by', '')); // email préparateur
        $carrier       = trim((string) $request->query->get('carrier', ''));     // shipping_carrier
        $tracking      = trim((string) $request->query->get('tracking', ''));    // tracking_number (LIKE)
        $deliveryState = trim((string) $request->query->get('delivery_state', '')); // état picking (draft, waiting, ...)

        // Vue: 'full' (complète) ou 'tablet' (simplifiée)
        $view = (string) $request->query->get('view', 'full');
        $view = \in_array($view, ['full','tablet'], true) ? $view : 'full';

        $page     = max(1, (int) $request->query->get('page', 1));
        $perPage  = (int) $request->query->get('per_page', 25);
        $perPage  = min(max($perPage, 5), 200);

        $sort     = (string) $request->query->get('sort', 'placed_at');
        $dir      = strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $sortable = [
            'external_order_id' => 'o.external_order_id',
            'status'            => 'o.status',
            'customer_name'     => 'o.customer_name',
            // 'total_amount' retiré de l’UI mais conservé si jamais utilisé ailleurs
            'total_amount'      => 'o.total_amount',
            'item_count'        => 'o.item_count',
            'source'            => 'o.source',
            'shipping_carrier'  => 'o.shipping_carrier',
            'tracking_number'   => 'o.tracking_number',
            'prepared_by'       => 'm.prepared_by',
            'placed_at'         => 'o.placed_at',
            'updated_at'        => 'o.updated_at',
            // Ajout tri “Doc logistique” (delivery_status Odoo)
            'delivery_status'   => 'o.delivery_status',
        ];
        $orderBy = $sortable[$sort] ?? 'o.placed_at';

        $qb = $this->db->createQueryBuilder()->from('sales_orders', 'o');
        // Récupérer l’email du préparateur depuis meta (si présent)
        $qb->leftJoin('o', 'sales_order_meta', 'm', 'm.order_id = o.id');

        if ($q !== '') {
            $qb->andWhere('(o.external_order_id LIKE :q OR o.customer_name LIKE :q OR o.customer_email LIKE :q OR o.tracking_number LIKE :q)')
               ->setParameter('q', '%' . $q . '%');
        }
        if ($status !== '') { $qb->andWhere('o.status = :status')->setParameter('status', $status); }
        if ($source !== '') { $qb->andWhere('o.source = :source')->setParameter('source', $source); }
        if ($from !== '')   { $qb->andWhere('o.placed_at >= :from')->setParameter('from', $from . ' 00:00:00'); }
        if ($to !== '')     { $qb->andWhere('o.placed_at <= :to')->setParameter('to', $to . ' 23:59:59'); }
        if ($preparedBy !== '') { $qb->andWhere('LOWER(m.prepared_by) = LOWER(:prep)')->setParameter('prep', $preparedBy); }
        if ($carrier !== '')    { $qb->andWhere('o.shipping_carrier = :carrier')->setParameter('carrier', $carrier); }
        if ($tracking !== '')   { $qb->andWhere('o.tracking_number LIKE :trk')->setParameter('trk', '%' . $tracking . '%'); }

        // Filtre État (livraison) via odoo_pickings.origin = o.odoo_name ou origin = o.external_order_id
        if ($deliveryState !== '') {
            $pickingsTableExists = true;
            try {
                $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne();
            } catch (\Throwable) {
                $pickingsTableExists = false;
            }

            if ($pickingsTableExists) {
                $qb->andWhere('EXISTS (SELECT 1 FROM odoo_pickings op WHERE (op.origin = o.odoo_name OR op.origin = o.external_order_id) AND op.state = :dstate)')
                   ->setParameter('dstate', $deliveryState);
            } else {
                // Si la table n'existe pas mais un filtre est demandé -> aucun résultat pour éviter une erreur SQL
                $qb->andWhere('1=0');
            }
        }

        // Total
        $countQb = clone $qb;
        $countQb->select('COUNT(*) AS cnt');
        $total = (int) $this->db->fetchOne($countQb->getSQL(), $countQb->getParameters());

        // Données page
        $qb->select('o.*, m.prepared_by AS prepared_by')
           ->orderBy($orderBy, $dir)
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        $items = $this->db->fetchAllAssociative($qb->getSQL(), $qb->getParameters());

        // Résolution en masse des noms de préparateurs (email -> label)
        $emails = [];
        foreach ($items as $it) {
            $e = \mb_strtolower(\trim((string)($it['prepared_by'] ?? '')));
            if ($e !== '') { $emails[$e] = true; }
        }
        $labels = [];
        if ($emails) {
            $list = array_keys($emails);
            // Tentative sur 'users' (colonnes existantes: name, email)
            try {
                foreach ($list as $em) {
                    $row = $this->db->fetchAssociative(
                        "SELECT COALESCE(NULLIF(TRIM(name), ''), email) AS label
                           FROM users
                          WHERE LOWER(email) = LOWER(?)",
                        [$em]
                    );
                    if ($row && !empty($row['label'])) { $labels[$em] = (string) $row['label']; }
                }
            } catch (\Throwable) {
                // Fallback sur "user" (colonnes name, email)
                foreach ($list as $em) {
                    try {
                        $row = $this->db->fetchAssociative(
                            "SELECT COALESCE(NULLIF(TRIM(name), ''), email) AS label
                               FROM \"user\"
                              WHERE LOWER(email) = LOWER(?)",
                            [$em]
                        );
                        if ($row && !empty($row['label'])) { $labels[$em] = (string) $row['label']; }
                    } catch (\Throwable) {}
                }
            }
        }
        foreach ($items as &$it) {
            $e = \mb_strtolower(\trim((string)($it['prepared_by'] ?? '')));
            if ($e !== '') {
                if (isset($labels[$e])) {
                    $it['prepared_by_label'] = $labels[$e];
                } else {
                    // Fallback lisible depuis l'email si pas de nom
                    $local = (string) \strstr($e, '@', true);
                    $it['prepared_by_label'] = \ucwords(\str_replace(['.', '_', '-'], ' ', $local !== '' ? $local : $e));
                }
            } else {
                $it['prepared_by_label'] = '';
            }
        }
        unset($it);

        // ====== État de livraison: refléter l’état des pickings (comme /admin/deliveries) ======
        // Classement pour choisir l’état le plus avancé si plusieurs pickings par commande
        $rank = ['cancel'=>-1, 'draft'=>10, 'waiting'=>20, 'confirmed'=>30, 'assigned'=>40, 'done'=>50];

        // Vérifie l'existence de la table odoo_pickings
        $pickingsTableExists = true;
        try { $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne(); }
        catch (\Throwable) { $pickingsTableExists = false; }

        if ($pickingsTableExists && $items) {
            // Collecte des origins à partir de odoo_name, fallback external_order_id
            $origins = [];
            foreach ($items as $it) {
                $origin = \trim((string)($it['odoo_name'] ?? ''));
                if ($origin === '') { $origin = \trim((string)($it['external_order_id'] ?? '')); }
                if ($origin !== '') { $origins[$origin] = true; }
            }

            if ($origins) {
                $keys = \array_keys($origins);
                $ph = \implode(',', \array_fill(0, \count($keys), '?'));
                $rows = $this->db->fetchAllAssociative(
                    'SELECT origin, state FROM odoo_pickings WHERE origin IN ('.$ph.')',
                    $keys
                );

                // Choisit le meilleur état par origin
                $bestByOrigin = [];
                foreach ($rows as $r) {
                    $o  = (string)($r['origin'] ?? '');
                    $st = \strtolower((string)($r['state'] ?? ''));
                    if ($o === '' || !isset($rank[$st])) { continue; }
                    $cur = $bestByOrigin[$o] ?? null;
                    if ($cur === null || $rank[$st] > $rank[$cur]) {
                        $bestByOrigin[$o] = $st;
                    }
                }

                // Affecte delivery_state sur chaque commande
                foreach ($items as &$it) {
                    $origin = \trim((string)($it['odoo_name'] ?? ''));
                    if ($origin === '') { $origin = \trim((string)($it['external_order_id'] ?? '')); }
                    $it['delivery_state'] = ($origin !== '' && isset($bestByOrigin[$origin])) ? $bestByOrigin[$origin] : null;
                }
                unset($it);
            }
        }

        // ====== Calcul de l'état de préparation (prep_state) ======
        // Règles de priorité: assigned > confirmed > waiting > done > cancel
        $rank = ['assigned'=>40, 'confirmed'=>30, 'waiting'=>20, 'done'=>10, 'cancel'=>0];

        // 1) Essai depuis payload_json (pickings inclus lors de la synchro)
        foreach ($items as &$it) {
            $best = null;
            $payload = (string)($it['payload_json'] ?? '');
            if ($payload !== '') {
                try {
                    $data = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                    $pickings = \is_array($data['pickings'] ?? null) ? $data['pickings'] : [];
                    foreach ($pickings as $p) {
                        $st = \strtolower((string)($p['state'] ?? ''));
                        if ($st === '') { continue; }
                        if (!isset($rank[$st])) { continue; }
                        if ($best === null || $rank[$st] > $rank[$best]) {
                            $best = $st;
                        }
                    }
                } catch (\Throwable) {
                    // ignore payload mal formé
                }
            }
            $it['prep_state'] = $best; // peut rester null si non trouvé ici
        }
        unset($it);

        // 2) Fallback/complément via la table odoo_pickings (si elle existe)
        $pickingsTableExists = true;
        try { $this->db->executeQuery('SELECT 1 FROM odoo_pickings LIMIT 1')->fetchOne(); }
        catch (\Throwable) { $pickingsTableExists = false; }

        if ($pickingsTableExists && $items) {
            $origins = [];
            foreach ($items as $it) {
                $origin = \trim((string)($it['odoo_name'] ?? ''));
                if ($origin === '') { $origin = \trim((string)($it['external_order_id'] ?? '')); }
                if ($origin !== '') { $origins[$origin] = true; }
            }
            if ($origins) {
                $keys = \array_keys($origins);
                $ph = \implode(',', \array_fill(0, \count($keys), '?'));
                $rows = $this->db->fetchAllAssociative(
                    'SELECT origin, state FROM odoo_pickings WHERE origin IN ('.$ph.')',
                    $keys
                );
                $bestByOrigin = [];
                foreach ($rows as $r) {
                    $o = (string)($r['origin'] ?? '');
                    $st = \strtolower((string)($r['state'] ?? ''));
                    if ($o === '' || !isset($rank[$st])) { continue; }
                    $cur = $bestByOrigin[$o] ?? null;
                    if ($cur === null || $rank[$st] > $rank[$cur]) {
                        $bestByOrigin[$o] = $st;
                    }
                }
                foreach ($items as &$it) {
                    if (!empty($it['prep_state'])) { continue; } // garde payload si déjà présent
                    $origin = \trim((string)($it['odoo_name'] ?? ''));
                    if ($origin === '') { $origin = \trim((string)($it['external_order_id'] ?? '')); }
                    if ($origin !== '' && isset($bestByOrigin[$origin])) {
                        $it['prep_state'] = $bestByOrigin[$origin];
                    }
                }
                unset($it);
            }
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        // Liste des préparateurs (ROLE_PREPARATEUR) pour le filtre
        $preparers = [];
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT name, email FROM users WHERE roles LIKE :needle ORDER BY name',
                ['needle' => '%"ROLE_PREPARATEUR"%']
            );
        } catch (\Throwable) {
            try {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT name, email FROM "user" WHERE roles LIKE :needle ORDER BY name',
                    ['needle' => '%"ROLE_PREPARATEUR"%']
                );
            } catch (\Throwable) { $rows = []; }
        }
        foreach ($rows as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '') { continue; }
            $name  = trim((string)($r['name'] ?? ''));
            $preparers[] = ['email' => $email, 'label' => ($name !== '' ? $name : $email)];
        }

        return $this->render('admin/orders/index.html.twig', [
            'items'         => $items,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $perPage,
            'totalPages'    => $totalPages,
            'sort'          => $sort,
            'dir'           => strtolower($dir),
            'q'             => $q,
            'status'        => $status,
            'source'        => $source,
            'placed_from'   => $from,
            'placed_to'     => $to,
            // Nouveaux filtres
            'prepared_by'   => $preparedBy,
            'carrier'       => $carrier,
            'tracking'      => $tracking,
            'delivery_state'=> $deliveryState,
            'preparers'     => $preparers,
            // Vue
            'view'          => $view,
        ]);
    }

    #[Route('/admin/orders/export.csv', name: 'admin_orders_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_PREPARATEUR')) {
            throw $this->createAccessDeniedException();
        }

        $q        = trim((string) $request->query->get('q', ''));
        $status   = trim((string) $request->query->get('status', ''));
        $source   = trim((string) $request->query->get('source', ''));
        $from     = trim((string) $request->query->get('placed_from', '')); // YYYY-MM-DD
        $to       = trim((string) $request->query->get('placed_to', ''));   // YYYY-MM-DD
        $sort     = (string) $request->query->get('sort', 'placed_at');
        $dir      = strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $includePayload = in_array((string) $request->query->get('include_payload', ''), ['1','true','yes'], true);

        $sortable = [
            'external_order_id' => 'o.external_order_id',
            'status'            => 'o.status',
            'customer_name'     => 'o.customer_name',
            'total_amount'      => 'o.total_amount',
            'item_count'        => 'o.item_count',
            'source'            => 'o.source',
            'placed_at'         => 'o.placed_at',
            'updated_at'        => 'o.updated_at',
        ];
        $orderBy = $sortable[$sort] ?? 'o.placed_at';

        $cols = [
            'o.external_order_id', 'o.status', 'o.customer_name', 'o.customer_email',
            'o.total_amount', 'o.currency', 'o.item_count', 'o.source',
            'o.shipping_carrier', 'o.shipping_service', 'o.tracking_number',
            'o.placed_at', 'o.updated_at'
        ];
        if ($includePayload) { $cols[] = 'o.payload_json'; }

        $qb = $this->db->createQueryBuilder()
            ->select(\implode(', ', $cols))
            ->from('sales_orders', 'o');

        if ($q !== '') {
            $qb->andWhere('(o.external_order_id LIKE :q OR o.customer_name LIKE :q OR o.customer_email LIKE :q OR o.tracking_number LIKE :q)')
               ->setParameter('q', '%' . $q . '%');
        }
        if ($status !== '') { $qb->andWhere('o.status = :status')->setParameter('status', $status); }
        if ($source !== '') { $qb->andWhere('o.source = :source')->setParameter('source', $source); }
        if ($from !== '')   { $qb->andWhere('o.placed_at >= :from')->setParameter('from', $from . ' 00:00:00'); }
        if ($to !== '')     { $qb->andWhere('o.placed_at <= :to')->setParameter('to', $to . ' 23:59:59'); }

        $qb->orderBy($orderBy, $dir);

        $response = new StreamedResponse(function () use ($qb, $includePayload) {
            \set_time_limit(0);
            $out = \fopen('php://output', 'w');
            \fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel

            $headers = [
                'external_order_id','status','customer_name','customer_email',
                'total_amount','currency','item_count','source',
                'shipping_carrier','shipping_service','tracking_number',
                'placed_at','updated_at'
            ];
            if ($includePayload) { $headers[] = 'payload_json'; }
            \fputcsv($out, $headers);

            $stmt = $this->db->executeQuery($qb->getSQL(), $qb->getParameters());
            foreach ($stmt->iterateAssociative() as $row) {
                $row['total_amount'] = \number_format((float) $row['total_amount'], 2, '.', '');
                $row['placed_at']    = $row['placed_at'] ? \date('Y-m-d H:i:s', \strtotime((string) $row['placed_at'])) : '';
                $row['updated_at']   = $row['updated_at'] ? \date('Y-m-d H:i:s', \strtotime((string) $row['updated_at'])) : '';

                $outRow = [
                    $row['external_order_id'] ?? '',
                    $row['status'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['customer_email'] ?? '',
                    $row['total_amount'] ?? '',
                    $row['currency'] ?? '',
                    $row['item_count'] ?? 0,
                    $row['source'] ?? '',
                    $row['shipping_carrier'] ?? '',
                    $row['shipping_service'] ?? '',
                    $row['tracking_number'] ?? '',
                    $row['placed_at'] ?? '',
                    $row['updated_at'] ?? '',
                ];
                if ($includePayload) {
                    // JSON compact (une seule ligne) pour ne pas casser le CSV
                    $outRow[] = (string) ($row['payload_json'] ?? '');
                }
                \fputcsv($out, $outRow);
            }
            \fclose($out);
        });

        $name = 'orders_' . \date('Ymd_His') . ($includePayload ? '_with_payload' : '') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
        return $response;
    }
}

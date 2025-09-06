<?php
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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $q        = trim((string) $request->query->get('q', ''));
        $status   = trim((string) $request->query->get('status', ''));
        $source   = trim((string) $request->query->get('source', ''));
        $from     = trim((string) $request->query->get('placed_from', '')); // YYYY-MM-DD
        $to       = trim((string) $request->query->get('placed_to', ''));   // YYYY-MM-DD

        $page     = max(1, (int) $request->query->get('page', 1));
        $perPage  = (int) $request->query->get('per_page', 25);
        $perPage  = min(max($perPage, 5), 200);

        $sort     = (string) $request->query->get('sort', 'placed_at');
        $dir      = strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

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

        $qb = $this->db->createQueryBuilder()->from('sales_orders', 'o');

        if ($q !== '') {
            $qb->andWhere('(o.external_order_id LIKE :q OR o.customer_name LIKE :q OR o.customer_email LIKE :q OR o.tracking_number LIKE :q)')
               ->setParameter('q', '%' . $q . '%');
        }
        if ($status !== '') { $qb->andWhere('o.status = :status')->setParameter('status', $status); }
        if ($source !== '') { $qb->andWhere('o.source = :source')->setParameter('source', $source); }
        if ($from !== '')   { $qb->andWhere('o.placed_at >= :from')->setParameter('from', $from . ' 00:00:00'); }
        if ($to !== '')     { $qb->andWhere('o.placed_at <= :to')->setParameter('to', $to . ' 23:59:59'); }

        // Total
        $countQb = clone $qb;
        $countQb->select('COUNT(*) AS cnt');
        $total = (int) $this->db->fetchOne($countQb->getSQL(), $countQb->getParameters());

        // Données page
        $qb->select('o.*')
           ->orderBy($orderBy, $dir)
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        $items = $this->db->fetchAllAssociative($qb->getSQL(), $qb->getParameters());
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return $this->render('admin/orders/index.html.twig', [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'totalPages'  => $totalPages,
            'sort'        => $sort,
            'dir'         => strtolower($dir),
            'q'           => $q,
            'status'      => $status,
            'source'      => $source,
            'placed_from' => $from,
            'placed_to'   => $to,
        ]);
    }

    #[Route('/admin/orders/export.csv', name: 'admin_orders_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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

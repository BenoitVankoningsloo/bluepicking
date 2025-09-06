<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminUserExportController extends AbstractController
{
    #[Route('/admin/users/export', name: 'admin_users_export', methods: ['GET'])]
    public function exportCsv(Request $request, UserRepository $repo): StreamedResponse
    {
        $q    = (string) $request->query->get('q', '');
        $role = (string) $request->query->get('role', 'any');        // any|admin|user
        $sort = (string) $request->query->get('sort', 'createdAt');  // id|email|name|createdAt
        $dir  = (string) $request->query->get('dir',  'DESC');       // ASC|DESC

        $qb = $repo->searchQb($q, $role !== 'any' ? $role : null, $sort, $dir);

        $response = new StreamedResponse(function () use ($qb) {
            $out = fopen('php://output', 'wb');

            // BOM UTF‑8 (Excel friendly)
            fwrite($out, "\xEF\xBB\xBF");

            // En‑têtes
            fputcsv($out, ['id', 'email', 'name', 'roles', 'created_at', 'updated_at'], ';');

            // Anti‑formules Excel
            $sanitize = static function (?string $v): string {
                $v = (string) $v;
                return ($v !== '' && preg_match('/^[=\-+@]/', $v)) ? "'".$v : $v;
            };

            $query = $qb->getQuery();
            $iter  = method_exists($query, 'toIterable') ? $query->toIterable() : $query->getResult();

            foreach ($iter as $user) {
                /** @var \App\Entity\User $user */
                fputcsv($out, [
                    $user->getId(),
                    $sanitize($user->getEmail()),
                    $sanitize($user->getName()),
                    implode(',', $user->getRoles()),
                    $user->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
                    $user->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '',
                ], ';');
            }

            if (is_resource($out)) {
                fclose($out);
            }
        });

        $filename    = sprintf('users-%s.csv', (new \DateTimeImmutable())->format('Ymd-His'));
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }
}


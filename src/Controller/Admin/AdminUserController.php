<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AbstractController
{
    private const ENTITY = User::class;

    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/admin/users', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $repo  = $this->em->getRepository(self::ENTITY);
        /** @var ClassMetadata $cm */
        $cm    = $this->em->getClassMetadata(self::ENTITY);

        $q     = \trim((string) $request->query->get('q', ''));
        $role  = (string) $request->query->get('role', 'any');
        $sort  = (string) $request->query->get('sort', 'id'); // fallback sûr
        $dir   = \strtoupper((string) $request->query->get('dir', 'DESC'));
        $dir   = $dir === 'ASC' ? 'ASC' : 'DESC';

        $qb = $repo->createQueryBuilder('u');

        // Recherche tolérante (ne cible que les champs existants)
        $this->applySearch($qb, $cm, $q);

        // Filtre par rôle (LIKE JSON) si applicable
        $this->applyRoleFilter($qb, $cm, $role);

        // Tri robuste
        foreach ($this->resolveSortFields($cm, $sort) as $sf) {
            $qb->addOrderBy('u.' . $sf, $dir);
        }

        // Désactiver le tri auto de KnpPaginator (on gère nous-mêmes)
        $pagination = $paginator->paginate(
            $qb,
            (int) $request->query->get('page', 1),
            20,
            [
                'sortFieldParameterName'     => '_unused_sort',
                'sortDirectionParameterName' => '_unused_dir',
            ]
        );

        return $this->render('admin/users.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    // IMPORTANT : export AVANT edit, pour éviter collision de route
    #[Route('/admin/users/export', name: 'admin_users_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $repo  = $this->em->getRepository(self::ENTITY);
        /** @var ClassMetadata $cm */
        $cm    = $this->em->getClassMetadata(self::ENTITY);

        $q     = \trim((string) $request->query->get('q', ''));
        $role  = (string) $request->query->get('role', 'any');
        $sort  = (string) $request->query->get('sort', 'id');
        $dir   = \strtoupper((string) $request->query->get('dir', 'DESC'));
        $dir   = $dir === 'ASC' ? 'ASC' : 'DESC';

        $qb = $repo->createQueryBuilder('u');
        $this->applySearch($qb, $cm, $q);
        $this->applyRoleFilter($qb, $cm, $role);
        foreach ($this->resolveSortFields($cm, $sort) as $sf) {
            $qb->addOrderBy('u.' . $sf, $dir);
        }

        /** @var object[] $entities */
        $entities = $qb->getQuery()->getResult();

        $fh = \fopen('php://temp', 'wb+');
        \fputcsv($fh, ['id', 'name', 'email', 'roles', 'created_at']);

        foreach ($entities as $e) {
            $id        = $this->callFirst($e, ['getId']);
            $name      = (string) ($this->callFirst($e, ['getName']) ?? '');
            $email     = (string) ($this->callFirst($e, ['getEmail']) ?? '');
            /** @var array<int,string>|null $rolesArr */
            $rolesArr  = $this->callFirst($e, ['getRoles']);
            $rolesStr  = \is_array($rolesArr) ? \implode('|', $rolesArr) : '';
            $createdAt = $this->callFirst($e, ['getCreatedAt', 'getCreated']);
            $created   = $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : '';

            if ($name === '') {
                $name = $email !== '' ? $email : (string) $id;
            }

            \fputcsv($fh, [$id, $name, $email, $rolesStr, $created]);
        }

        \rewind($fh);
        $csv = (string) \stream_get_contents($fh);
        \fclose($fh);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users_export.csv"',
        ]);
    }

    #[Route('/admin/users/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->em->getRepository(self::ENTITY)->find($id);
        if (!$user) {
            $this->addFlash('danger', 'Utilisateur introuvable.');
            return $this->redirectToRoute('admin_users');
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('edit_user_' . $id, $token)) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('admin_user_edit', ['id' => $id]);
            }

            $name = \trim((string) $request->request->get('name', ''));
            /** @var array<int,string>|string|null $postedRoles */
            $postedRoles = $request->request->all('roles');

            $roles = \is_array($postedRoles)
                ? \array_values(\array_unique(\array_map('strval', $postedRoles)))
                : (\is_string($postedRoles) && $postedRoles !== '' ? [$postedRoles] : []);

            // Sanitize : on ne garde que des rôles connus
            $allowed = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PREPARATEUR'];
            $roles   = \array_values(\array_intersect($roles, $allowed));
            if (!\in_array('ROLE_USER', $roles, true)) {
                $roles[] = 'ROLE_USER';
            }

            if ($name !== '' && \method_exists($user, 'setName')) {
                $user->setName($name);
            }
            $user->setRoles($roles);

            $this->em->flush();

            $this->addFlash('success', 'Utilisateur mis à jour.');
            return $this->redirectBackToList($request);
        }

        return $this->render('admin/user_edit.html.twig', [
            'user' => $user,
        ]);
    }

    private function redirectBackToList(Request $request): RedirectResponse
    {
        // On conserve q/role/sort/dir si présents dans la query d'origine de la liste
        $params = [
            'q'    => $request->query->get('q'),
            'role' => $request->query->get('role'),
            'sort' => $request->query->get('sort'),
            'dir'  => $request->query->get('dir'),
        ];
        return $this->redirectToRoute('admin_users', \array_filter($params, static fn($v) => $v !== null && $v !== ''));
    }

    private function applySearch(\Doctrine\ORM\QueryBuilder $qb, ClassMetadata $cm, string $q): void
    {
        if ($q === '') {
            return;
        }

        $orX = $qb->expr()->orX();
        foreach (['name', 'email'] as $field) {
            if ($cm->hasField($field)) {
                $orX->add("u.$field LIKE :term");
            }
        }

        if (\count($orX->getParts()) > 0) {
            $qb->andWhere($orX)->setParameter('term', '%' . $q . '%');
        }
    }

    private function applyRoleFilter(\Doctrine\ORM\QueryBuilder $qb, ClassMetadata $cm, string $role): void
    {
        if ($role === '' || $role === 'any') {
            return;
        }
        if ($cm->hasField('roles')) {
            // MySQL JSON/text : on cherche la chaîne "ROLE_XYZ" dans le JSON
            $qb->andWhere('u.roles LIKE :needle')
               ->setParameter('needle', '%"'. $role .'"%');
        }
    }

    /** @return list<string> */
    private function resolveSortFields(ClassMetadata $cm, string $sort): array
    {
        $allowed = ['id', 'name', 'email', 'createdAt', 'created'];
        if (!\in_array($sort, $allowed, true)) {
            $sort = 'id';
        }

        // Tri par name → fallback si besoin
        if ($sort === 'name') {
            return $cm->hasField('name') ? ['name'] : ['id'];
        }

        // createdAt/created → fallback id
        if (\in_array($sort, ['createdAt', 'created'], true)) {
            return $cm->hasField($sort) ? [$sort] : ['id'];
        }

        return $cm->hasField($sort) ? [$sort] : ['id'];
    }

    private function callFirst(object $e, array $methods): mixed
    {
        foreach ($methods as $m) {
            if (\method_exists($e, $m)) {
                return $e->{$m}();
            }
        }
        return null;
    }
}


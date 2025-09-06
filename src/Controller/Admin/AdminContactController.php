<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ContactMessage; // Entité explicite
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminContactController extends AbstractController
{
    private const ENTITY = ContactMessage::class; // Entité unique et lisible

    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/admin/contacts', name: 'admin_contacts', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $repo  = $this->em->getRepository(self::ENTITY);
        /** @var ClassMetadata $cm */
        $cm    = $this->em->getClassMetadata(self::ENTITY);

        $q    = \trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id'); // fallback sûr
        $dir  = \strtoupper((string) $request->query->get('dir', 'DESC'));
        $dir  = $dir === 'ASC' ? 'ASC' : 'DESC';

        $qb = $repo->createQueryBuilder('c');

        $this->applySearch($qb, $cm, $q);

        foreach ($this->resolveSortFields($cm, $sort) as $sf) {
            $qb->addOrderBy('c.' . $sf, $dir);
        }

        // Désactive le tri auto de KnpPaginator (sinon il tente d'utiliser "sort" sans alias)
        $pagination = $paginator->paginate(
            $qb,
            (int) $request->query->get('page', 1),
            20,
            [
                'sortFieldParameterName'      => '_unused_sort',
                'sortDirectionParameterName'  => '_unused_dir',
            ]
        );

        return $this->render('admin/contacts.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    // IMPORTANT : Export AVANT show, pour ne pas matcher {id} = "export"
    #[Route('/admin/contacts/export', name: 'admin_contacts_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $repo  = $this->em->getRepository(self::ENTITY);
        /** @var ClassMetadata $cm */
        $cm    = $this->em->getClassMetadata(self::ENTITY);

        $q    = \trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id');
        $dir  = \strtoupper((string) $request->query->get('dir', 'DESC'));
        $dir  = $dir === 'ASC' ? 'ASC' : 'DESC';

        $qb = $repo->createQueryBuilder('c');
        $this->applySearch($qb, $cm, $q);
        foreach ($this->resolveSortFields($cm, $sort) as $sf) {
            $qb->addOrderBy('c.' . $sf, $dir);
        }

        /** @var object[] $entities */
        $entities = $qb->getQuery()->getResult();

        $fh = \fopen('php://temp', 'wb+');
        \fputcsv($fh, ['id', 'name', 'email', 'created_at', 'message']);

        foreach ($entities as $e) {
            $id        = $this->callFirst($e, ['getId']);
            $email     = (string) ($this->callFirst($e, ['getEmail']) ?? '');
            $createdAt = $this->callFirst($e, ['getCreatedAt', 'getCreated']);
            $created   = $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : '';

            $name = (string) ($this->callFirst($e, ['getName']) ?? '');
            if ($name === '') {
                $fn = (string) ($this->callFirst($e, ['getFirstName']) ?? '');
                $ln = (string) ($this->callFirst($e, ['getLastName']) ?? '');
                $name = \trim($fn . ' ' . $ln);
            }
            if ($name === '') {
                $name = $email !== '' ? $email : (string) $id;
            }

            $msg = (string) ($this->callFirst($e, ['getMessage', 'getNotes', 'getContent']) ?? '');
            $msg = \preg_replace("/\r?\n/", ' ', $msg);

            \fputcsv($fh, [$id, $name, $email, $created, $msg]);
        }

        \rewind($fh);
        $csv = (string) \stream_get_contents($fh);
        \fclose($fh);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="contacts_export.csv"',
        ]);
    }

    #[Route('/admin/contacts/{id}', name: 'admin_contact_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $contact = $this->em->getRepository(self::ENTITY)->find($id);

        if (!$contact) {
            $this->addFlash('danger', 'Message introuvable.');
            return $this->redirectToRoute('admin_contacts');
        }

        return $this->render('admin/contact_show.html.twig', [
            'contact' => $contact,
        ]);
    }

    #[Route('/admin/contacts/{id}/delete', name: 'admin_contact_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $contact = $this->em->getRepository(self::ENTITY)->find($id);

        if (!$contact) {
            $this->addFlash('danger', 'Message introuvable.');
            return $this->redirectToRoute('admin_contacts', $request->query->all());
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_contact_' . $id, $token)) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_contacts', $request->query->all());
        }

        $this->em->remove($contact);
        $this->em->flush();

        $this->addFlash('success', 'Message supprimé.');
        return $this->redirectToRoute('admin_contacts', $request->query->all());
    }

    private function applySearch(\Doctrine\ORM\QueryBuilder $qb, ClassMetadata $cm, string $q): void
    {
        if ($q === '') {
            return;
        }

        $orX = $qb->expr()->orX();
        foreach (['name', 'firstName', 'lastName', 'email', 'message', 'notes', 'content'] as $field) {
            if ($cm->hasField($field)) {
                $orX->add("c.$field LIKE :term");
            }
        }

        if (\count($orX->getParts()) > 0) {
            $qb->andWhere($orX)->setParameter('term', '%' . $q . '%');
        }
    }

    /** @return list<string> */
    private function resolveSortFields(ClassMetadata $cm, string $sort): array
    {
        // Champs autorisés (tu peux en ajouter ici si ton entité les expose)
        $allowed = ['id', 'name', 'firstName', 'lastName', 'email', 'createdAt', 'created'];

        if (!\in_array($sort, $allowed, true)) {
            $sort = 'id'; // fallback sûr
        }

        if ($sort === 'name') {
            if ($cm->hasField('name')) {
                return ['name'];
            }
            $fallback = [];
            if ($cm->hasField('lastName')) {
                $fallback[] = 'lastName';
            }
            if ($cm->hasField('firstName')) {
                $fallback[] = 'firstName';
            }
            return $fallback !== [] ? $fallback : ['id'];
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


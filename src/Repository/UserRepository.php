<?php /** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    /** @var array<string, string> */
    private const SORT_MAP = [
        'id'        => 'u.id',
        'name'      => 'u.name',
        'email'     => 'u.email',
        'createdAt' => 'u.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** @param PasswordAuthenticatedUserInterface $user */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }
        $user->setPassword($newHashedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }

    public function searchQb(
        ?string $q = null,
        ?string $role = null,
        string $sort = 'createdAt',
        string $dir = 'DESC'
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('u');

        if ($q) {
            /** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
            $qb->andWhere('u.name LIKE :q OR u.email LIKE :q')
               ->setParameter('q', "%{$q}%");
        }

        if ($role && $role !== 'any') {
            // Filtrage compatible partout : roles JSON stockés en string, on cherche la valeur avec guillemets
            $qb->andWhere('u.roles LIKE :roleLike')
               ->setParameter('roleLike', '%"'.$role.'"%');
        }

        $sortExpr = self::SORT_MAP[$sort] ?? self::SORT_MAP['createdAt'];
        $dir      = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        return $qb->orderBy($sortExpr, $dir);
    }
}


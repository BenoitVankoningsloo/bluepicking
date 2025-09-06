<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

final class ContactMessageRepository extends ServiceEntityRepository
{
    /** @var array<string, string> */
    private const SORT_MAP = [
        'id'        => 'c.id',
        'name'      => 'c.name',
        'email'     => 'c.email',
        'createdAt' => 'c.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    public function searchQb(?string $q = null, string $sort = 'createdAt', string $dir = 'DESC'): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c');

        if ($q) {
            $qb->andWhere('c.name LIKE :q OR c.email LIKE :q OR c.message LIKE :q')
               ->setParameter('q', "%{$q}%");
        }

        $sortExpr = self::SORT_MAP[$sort] ?? self::SORT_MAP['createdAt'];
        $dir      = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        return $qb->orderBy($sortExpr, $dir);
    }
}


<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRoleChangeLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRoleChangeLogRepository::class)]
#[ORM\Table(name: 'user_role_change_logs')]
class UserRoleChangeLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $target;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $admin;

    #[ORM\Column(type: 'json')]
    private array $oldRoles;

    #[ORM\Column(type: 'json')]
    private array $newRoles;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $changedAt;

    public function __construct(User $target, ?User $admin, array $oldRoles, array $newRoles)
    {
        $this->target = $target;
        $this->admin = $admin;
        $this->oldRoles = array_values(array_unique($oldRoles));
        $this->newRoles = array_values(array_unique($newRoles));
        $this->changedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTarget(): User { return $this->target; }
    public function getAdmin(): ?User { return $this->admin; }
    public function getOldRoles(): array { return $this->oldRoles; }
    public function getNewRoles(): array { return $this->newRoles; }
    public function getChangedAt(): DateTimeImmutable { return $this->changedAt; }
}


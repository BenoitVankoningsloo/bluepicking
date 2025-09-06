<?php /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
/** @noinspection ALL */
/** @noinspection PhpFieldAssignmentTypeMismatchInspection */
/** @noinspection PhpFieldAssignmentTypeMismatchInspection */
/** @noinspection PhpFieldAssignmentTypeMismatchInspection */
/** @noinspection PhpFieldAssignmentTypeMismatchInspection */
/** @noinspection ALL */
/** @noinspection PhpFieldAssignmentTypeMismatchInspection */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResetPasswordRequestRepository;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user;

    /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
    public function __construct(object $user, DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->user = $user;
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): object
    {
        return $this->user;
    }
}


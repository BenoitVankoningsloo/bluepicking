<?php
declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de changement de mot de passe depuis l'espace compte.
 * - Nécessite le mot de passe actuel + un nouveau mot de passe.
 * - Champs non mappés, l'update est gérée dans le contrôleur.
 */
final class PasswordChangeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'constraints' => [new Assert\NotBlank()],
            ])
          ->add('newPassword', PasswordType::class, [
                'label' => 'Nouveau mot de passe',
                'mapped' => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 12)],
            ]);
    }
}


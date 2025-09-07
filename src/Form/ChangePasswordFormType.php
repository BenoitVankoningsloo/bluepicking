<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire de réinitialisation de mot de passe via lien (token).
 * - Utilisé après validation du token (ResetPasswordController::reset).
 * - Le champ plainPassword (répété) n'est pas mappé sur l'entité.
 */
class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options'  => ['label' => 'Nouveau mot de passe'],
            'second_options' => ['label' => 'Confirmer le mot de passe'],
            'invalid_message' => 'Les deux mots de passe doivent être identiques.',
            'mapped' => false,
            'constraints' => [
                new NotBlank(message: 'Merci de choisir un mot de passe.'),
                new Length(min: 12, minMessage: '12 caractères minimum.'),
            ],
        ]);
    }
}


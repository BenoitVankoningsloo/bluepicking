<?php declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom affiché',
                'constraints' => [
                    new Assert\NotBlank(message: 'Nom requis'),
                    new Assert\Length(max: 120, maxMessage: '120 caractères maximum'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'constraints' => [
                    new Assert\NotBlank(message: 'Email requis'),
                    new Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5, message: 'Email invalide'),
                ],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôles',
                'choices' => [
                    'Utilisateur' => 'ROLE_USER',
                    'Admin' => 'ROLE_ADMIN',
                ],
                'multiple' => true,
                'expanded' => true,
                'help' => 'ROLE_USER est toujours ajouté automatiquement',
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'Nouveau mot de passe (optionnel)',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}


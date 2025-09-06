<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class RegisterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b->add('name', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new Assert\NotBlank()],
            ])
          ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
          ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8)],
            ])
          ->add('agreeTerms', CheckboxType::class, [
                'label' => 'J’accepte les conditions',
                'mapped' => false,
                'required' => true,
                'constraints' => [new Assert\IsTrue(message: 'Vous devez accepter les conditions.')],
            ]);
    }
}


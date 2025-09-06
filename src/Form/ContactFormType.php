<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Votre nom',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire'),
                    new Assert\Length(max: 120),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Votre email',
                'constraints' => [
                    new Assert\NotBlank(message: 'L’email est obligatoire'),
                    new Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5, message: 'Email invalide'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Votre message',
                'attr' => ['rows' => 6],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le message est obligatoire'),
                    new Assert\Length(min: 5, max: 5000),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactMessage::class]);
    }
}


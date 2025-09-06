<?php /** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterType;
use App\Security\RecaptchaVerifier;
use App\Service\UserRegistrationMailer;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use function is_string;
use function method_exists;

final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RecaptchaVerifier $recaptcha,
        private readonly UserRegistrationMailer $registrationMailer,
    ) {}

    #[Route('/register', name: 'app_register', methods: ['GET','POST'])]
    public function register(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(RegisterType::class, $user);
        $form->handleRequest($request);

        $siteKey = '';
        try {
            $siteKey = (string) $this->getParameter('app.recaptcha_site_key');
        } catch (Throwable) {
            $siteKey = '';
        }

        if ($form->isSubmitted()) {
            $token = $request->request->get('g-recaptcha-response');
            $isHuman = $this->recaptcha->verify(is_string($token) ? $token : null, $request->getClientIp());
            if (!$isHuman) {
                $this->addFlash('danger', 'Veuillez valider le reCAPTCHA.');
                return $this->render('security/register.html.twig', [
                    'registrationForm'   => $form->createView(),
                    'recaptcha_site_key' => $siteKey,
                ]);
            }

            if ($form->isValid()) {
                // Vérif applicative de l'unicité email (retourne une erreur de formulaire au lieu d'une 500)
                $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
                if ($existing !== null) {
                    $form->get('email')->addError(new FormError('Cet email est déjà utilisé.'));
                    return $this->render('security/register.html.twig', [
                        'registrationForm'   => $form->createView(),
                        'recaptcha_site_key' => $siteKey,
                    ]);
                }

                $plain = $form->has('plainPassword') ? (string) $form->get('plainPassword')->getData() : '';
                if ($plain !== '') {
                    $user->setPassword($this->hasher->hashPassword($user, $plain));
                }
                if (method_exists($user, 'setCreatedAt')) {
                    $user->setCreatedAt(new DateTimeImmutable('now'));
                }

                try {
                    $this->em->persist($user);
                    $this->em->flush();
                } catch (UniqueConstraintViolationException) {
                    // Filet de sécurité si concurrence ou validation non exécutée
                    $form->get('email')->addError(new FormError('Cet email est déjà utilisé.'));
                    $this->addFlash('danger', 'Cet email est déjà utilisé. Veuillez en choisir un autre.');
                    return $this->render('security/register.html.twig', [
                        'registrationForm'   => $form->createView(),
                        'recaptcha_site_key' => $siteKey,
                    ]);
                }

                // Envoi de l'email de bienvenue (ne bloque pas la redirection si échec)
                try {
                    $this->registrationMailer->sendWelcome($user->getEmail(), $user->getName());
                } catch (Throwable) {
                    // Optionnel: logger l'erreur si un logger est disponible
                }

                $this->addFlash('success', 'Votre compte a été créé. Vous pouvez vous connecter.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/register.html.twig', [
            'registrationForm'   => $form->createView(),
            'recaptcha_site_key' => $siteKey,
        ]);
    }
}


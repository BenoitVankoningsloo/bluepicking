<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterType;
use App\Security\RecaptchaVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RecaptchaVerifier $recaptcha,
    ) {}

    #[Route('/register', name: 'app_register', methods: ['GET','POST'])]
    public function register(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(RegisterType::class, $user);
        $form->handleRequest($request);

        $siteKey = '';
        try {
            /** @var string $siteKey */
            $siteKey = (string) $this->getParameter('app.recaptcha_site_key');
        } catch (\Throwable) {
            $siteKey = '';
        }

        if ($form->isSubmitted()) {
            $token = $request->request->get('g-recaptcha-response');
            $isHuman = $this->recaptcha->verify(\is_string($token) ? $token : null, $request->getClientIp());
            if (!$isHuman) {
                $this->addFlash('danger', 'Veuillez valider le reCAPTCHA.');
                return $this->render('security/register.html.twig', [
                    'registrationForm'   => $form->createView(),
                    'recaptcha_site_key' => $siteKey,
                ]);
            }

            if ($form->isValid()) {
                $plain = $form->has('plainPassword') ? (string) $form->get('plainPassword')->getData() : '';
                if ($plain !== '') {
                    $user->setPassword($this->hasher->hashPassword($user, $plain));
                }
                if (\method_exists($user, 'setCreatedAt')) {
                    $user->setCreatedAt(new \DateTimeImmutable('now'));
                }

                $this->em->persist($user);
                $this->em->flush();

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


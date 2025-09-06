<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET','POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error        = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        $siteKey = '';
        try {
            /** @var string $siteKey */
            $siteKey = (string) $this->getParameter('app.recaptcha_site_key');
        } catch (\Throwable) {
            $siteKey = '';
        }

        return $this->render('security/login.html.twig', [
            'last_username'      => $lastUsername,
            'error'              => $error,
            'recaptcha_site_key' => $siteKey,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the firewall.');
    }
}


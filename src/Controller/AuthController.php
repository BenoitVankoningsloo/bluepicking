<?php /** @noinspection ALL */
declare(strict_types=1);

namespace App\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\Attribute\RateLimiter;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Throwable;

final class AuthController extends AbstractController
{
    /** @noinspection PhpConditionAlreadyCheckedInspection */
    #[RateLimiter('login_form')]
    #[Route('/login', name: 'app_login', methods: ['GET','POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error        = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        $siteKey = '';
        try {
            $siteKey = (string) $this->getParameter('app.recaptcha_site_key');
        } catch (Throwable) {
            /** @noinspection PhpConditionAlreadyCheckedInspection */
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
        throw new LogicException('Intercepted by the firewall.');
    }
}


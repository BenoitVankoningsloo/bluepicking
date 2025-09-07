<?php declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use function is_string;

final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RecaptchaVerifier $recaptcha,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = \trim((string) $request->request->get('email', ''));
        $password = \trim((string) $request->request->get('password', ''));
        $csrf = (string) $request->request->get('_csrf_token', '');

        // Mémoriser le dernier identifiant saisi (clé officielle côté Security Http)
        $request->getSession()?->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        // reCAPTCHA v2 (token du POST /login)
        $captchaToken = $request->request->get('g-recaptcha-response');
        $ok = $this->recaptcha->verify(is_string($captchaToken) ? $captchaToken : null, $request->getClientIp());
        if (!$ok) {
            throw new CustomUserMessageAuthenticationException('Veuillez valider le reCAPTCHA.');
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrf),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }
        return new RedirectResponse($this->urlGenerator->generate('home'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }
}


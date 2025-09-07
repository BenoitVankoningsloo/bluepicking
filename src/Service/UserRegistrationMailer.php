<?php declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UserRegistrationMailer
{
    public function __construct(
        private readonly MailerInterface                         $mailer,
        private readonly UrlGeneratorInterface                   $urlGenerator,
        #[Autowire('%app.contact.to%')] private readonly string  $adminEmail,
        #[Autowire('%app.mailer.from%')] private readonly string $defaultFrom,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendWelcome(string $userEmail, ?string $userFirstName = null): void
    {
        $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address($this->defaultFrom, 'BluePicking'))
            ->to(new Address($userEmail, $userFirstName ?? ''))
            ->cc($this->adminEmail)
            ->subject('Bienvenue sur BluePicking')
            ->htmlTemplate('emails/registration_success.html.twig')
            ->context([
                'firstName' => $userFirstName,
                'loginUrl'  => $loginUrl,
                'userEmail' => $userEmail,
            ]);

        $this->mailer->send($email);
    }
}

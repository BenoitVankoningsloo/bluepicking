<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\ContactMessage; // adapte si ton entité a un autre nom
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Throwable;
use Twig\Environment;

final readonly class ContactEmailer
{
    public function __construct(
        private MailerInterface   $mailer,
        private Environment $twig,
        private LoggerInterface   $logger,
        private string            $toAddress,   // DESTINATAIRE ADMIN (CONTACT_TO)
        private string            $fromAddress, // EXPÉDITEUR FIXE (CONTACT_FROM_EMAIL)
        private bool              $enabled,
    ) {}

    /**
     * Envoie l’email à l’admin uniquement.
     */
    public function sendAdminNotification(ContactMessage $msg): bool
    {
        if (!$this->enabled) {
            $this->logger->warning('ContactEmailer disabled: CONTACT_EMAIL_ENABLED=0');
            return false;
        }

        try {
            $traceId = bin2hex(random_bytes(8));
            $email   = $this->buildAdminEmail($msg, $traceId);
            $this->mailer->send($email);
            $this->logger->info('ContactEmailer admin sent', [
                'id' => $msg->getId(), 'trace' => $traceId, 'to' => $this->toAddress,
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('ContactEmailer admin send failed', [
                'error' => $e->getMessage(), 'id' => $msg->getId(),
            ]);
            return false;
        }
    }

    /**
     * Envoie l’accusé de réception à l’expéditeur uniquement.
     */
    public function sendAutoReply(ContactMessage $msg): bool
    {
        if (!$this->enabled) {
            $this->logger->warning('ContactEmailer disabled: CONTACT_EMAIL_ENABLED=0');
            return false;
        }
        if (!$msg->getEmail()) {
            $this->logger->info('ContactEmailer ack skipped: no email on message', ['id' => $msg->getId()]);
            return false;
        }

        try {
            $traceId = bin2hex(random_bytes(8));
            $ack     = $this->buildAckEmail($msg, $traceId);
            $this->mailer->send($ack);
            $this->logger->info('ContactEmailer ack sent', [
                'id' => $msg->getId(), 'trace' => $traceId, 'to' => $msg->getEmail(),
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('ContactEmailer ack send failed', [
                'error' => $e->getMessage(), 'id' => $msg->getId(),
            ]);
            return false;
        }
    }

    /**
     * Retourne [bool $adminSent, bool $ackSent].
     */
    public function sendAdminAndAck(ContactMessage $msg): array
    {
        $admin = $this->sendAdminNotification($msg);
        $ack   = $this->sendAutoReply($msg);
        return [$admin, $ack];
    }

    // -----------------------
    // Builders
    // -----------------------

    private function exportVars(ContactMessage $msg): array
    {
        $contactId      = (int) $msg->getId();
        $contactEmail   = $msg->getEmail() ?? '';
        $contactName    = method_exists($msg, 'getName')    ? $msg->getName() ?? '' : '';
        $contactSubject = method_exists($msg, 'getSubject') ? (string) ($msg->getSubject() ?? '') : '';
        $contactBody    = method_exists($msg, 'getMessage') ? $msg->getMessage() ?? '' : '';
        $contactPhone   = method_exists($msg, 'getPhone')   ? (string) ($msg->getPhone() ?? '')   : '';
        $contactCompany = method_exists($msg, 'getCompany') ? (string) ($msg->getCompany() ?? '') : '';
        $submittedAt    = method_exists($msg, 'getCreatedAt') && $msg->getCreatedAt()
            ? $msg->getCreatedAt()->format('Y-m-d H:i:s')
            : '';

        return [
            // ⚠️ ne pas utiliser la clé "email" (réservée par TemplatedEmail)
            'contact_id'      => $contactId,
            'contact_email'   => $contactEmail,
            'contact_name'    => $contactName,
            'contact_subject' => $contactSubject,
            'contact_body'    => $contactBody,
            'contact_phone'   => $contactPhone,
            'contact_company' => $contactCompany,
            'submitted_at'    => $submittedAt,
        ];
    }

    private function buildAdminEmail(ContactMessage $msg, string $traceId): TemplatedEmail
    {
        $vars = $this->exportVars($msg);

        $from = $this->fromAddress !== ''
            ? new Address($this->fromAddress, 'Bluepicking Bot')
            : new Address($this->toAddress, 'Bluepicking Bot');

        $text = sprintf(
            "Nouveau message de contact #%d\nDe: %s <%s>\nSujet: %s\nTéléphone: %s\nSociété: %s\nDate: %s\n\nMessage:\n%s\n",
            $vars['contact_id'],
            $vars['contact_name'] ?: '(non fourni)',
            $vars['contact_email'] ?: '(non fourni)',
            $vars['contact_subject'] ?: '(non fourni)',
            $vars['contact_phone'] ?: '(non fourni)',
            $vars['contact_company'] ?: '(non fourni)',
            $vars['submitted_at'] ?: '(n/a)',
            $vars['contact_body'] ?: '(vide)',
        );

        $email = (new TemplatedEmail())
            ->from($from)
            ->to(new Address($this->toAddress, 'Support'))
            ->replyTo(new Address($vars['contact_email'] ?: $this->toAddress)) // si pas d'email, fallback support
            ->subject(sprintf('[Contact] #%d %s', $vars['contact_id'], $vars['contact_subject'] ?: 'Nouveau message'))
            ->htmlTemplate('emails/contact_message.html.twig')
            ->context($vars)
            ->text($text);

        $email->getHeaders()->addTextHeader('X-MJ-CustomID', $traceId);
        $email->getHeaders()->addTextHeader('X-MJ-EventPayload', (string) $vars['contact_id']);
        $email->getHeaders()->addTextHeader('X-Trace-Id', $traceId);

        return $email;
    }

    private function buildAckEmail(ContactMessage $msg, string $traceId): TemplatedEmail
    {
        $vars = $this->exportVars($msg);

        $from = $this->fromAddress !== ''
            ? new Address($this->fromAddress, 'Bluepicking Bot')
            : new Address($this->toAddress, 'Bluepicking Bot');

        $text = sprintf(
            "Bonjour %s,\n\nNous avons bien reçu votre message.\n\nSujet: %s\nDate: %s\n\nCopie de votre message:\n%s\n\n--\nBluepicking",
            $vars['contact_name'] ?: $vars['contact_email'],
            $vars['contact_subject'] ?: '(non fourni)',
            $vars['submitted_at'] ?: '(n/a)',
            $vars['contact_body'] ?: '(vide)',
        );

        $ack = (new TemplatedEmail())
            ->from($from)
            ->to(new Address($vars['contact_email']))
            ->replyTo(new Address($this->toAddress, 'Support'))
            ->subject('Nous avons bien reçu votre message')
            ->htmlTemplate('emails/contact_auto_reply.html.twig')
            ->context($vars)
            ->text($text);

        $ack->getHeaders()->addTextHeader('X-MJ-CustomID', $traceId);
        $ack->getHeaders()->addTextHeader('X-MJ-EventPayload', (string) $vars['contact_id']);
        $ack->getHeaders()->addTextHeader('X-Trace-Id', $traceId);

        return $ack;
    }
}


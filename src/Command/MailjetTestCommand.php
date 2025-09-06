<?php declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

#[AsCommand(name: 'app:mail:test', description: 'Envoie un email de test via Mailjet')]
final class MailjetTestCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%app.contact.to%')] private readonly string $defaultTo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Destinataire', $this->defaultTo)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Expéditeur', 'no-reply@bluepicking.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $to   = (string) $input->getOption('to');
        $from = (string) $input->getOption('from');

        try {
            $email = (new Email())
                ->from(new Address($from, 'BluePicking'))
                ->to($to)
                ->subject('Test Mailjet ✔')
                ->text('Mailjet OK (texte)')
                ->html('<p><strong>Mailjet OK</strong> (HTML)</p>');

            $this->mailer->send($email);
            $io->success(sprintf('Email envoyé à %s', $to));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}


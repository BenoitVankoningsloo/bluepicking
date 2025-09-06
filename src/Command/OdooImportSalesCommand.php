<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\OdooSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:odoo:import-sales', description: 'Importe des sale.orders Odoo en base locale')]
final class OdooImportSalesCommand extends Command
{
    // Compat older Symfony:
    protected static $defaultName = 'app:odoo:import-sales';
    protected static $defaultDescription = 'Importe des sale.orders Odoo en base locale';

    public function __construct(private readonly OdooSyncService $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('states', null, InputOption::VALUE_REQUIRED, 'États Odoo (csv)', 'draft,sent,sale,done')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'Date min (YYYY-MM-DD)')
            ->addOption('until', null, InputOption::VALUE_OPTIONAL, 'Date max (YYYY-MM-DD)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre max à importer', '500')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Décalage initial', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $states = array_filter(array_map('trim', explode(',', (string)$input->getOption('states'))));
        $since  = $input->getOption('since') ? (string)$input->getOption('since') : null;
        $until  = $input->getOption('until') ? (string)$input->getOption('until') : null;
        $limit  = max(1, (int)$input->getOption('limit'));
        $offset = max(0, (int)$input->getOption('offset'));

        $io->title('Import Odoo → Bluepicking');
        $io->writeln(sprintf('États: %s; Période: %s → %s; limit=%d offset=%d',
            implode(',', $states), $since ?? '—', $until ?? '—', $limit, $offset));

        try {
            $res = $this->sync->syncBatch($states, $since, $until, $limit, $offset);
            $io->success(sprintf('Importés: %d (dernier: %s)', $res['imported'], $res['last_ref'] ?? '—'));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}


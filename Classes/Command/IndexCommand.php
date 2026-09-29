<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Command;

use Maispace\MaiSearch\Domain\Dto\ProvisioningResult;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mai-search:index',
    description: 'Reindex all content into the active search backend',
)]
final class IndexCommand extends Command
{
    public function __construct(
        private readonly IndexManagementService $indexManagementService,
        private readonly ProvisioningService $provisioningService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'schema-only',
            null,
            InputOption::VALUE_NONE,
            'Only provision the active backend (do not index content)',
        );

        $this->addOption(
            'stats',
            null,
            InputOption::VALUE_NONE,
            'Only show index statistics (do not modify indexes)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('stats')) {
            return $this->showStats($io);
        }

        if ($input->getOption('schema-only')) {
            return $this->provisionOnly($io);
        }

        return $this->reindexAll($io);
    }

    private function provisionOnly(SymfonyStyle $io): int
    {
        $io->title('Provisioning the active search backend');

        $result = $this->provision($io);
        if ($result === null) {
            return Command::FAILURE;
        }

        $this->reportProvisioning($io, $result);

        return Command::SUCCESS;
    }

    private function reindexAll(SymfonyStyle $io): int
    {
        $io->title('Starting full reindex');

        $io->section('Step 1: Provisioning search infrastructure');
        $result = $this->provision($io);
        if ($result === null) {
            return Command::FAILURE;
        }

        $this->reportProvisioning($io, $result);

        $io->section(sprintf('Step 2: Reindexing all content into %s', $result->backend));
        try {
            $this->indexManagementService->reindexAll();
            $io->success('Reindex completed successfully');

            return $this->showStats($io);
        } catch (\Throwable $e) {
            $io->error('Reindex failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function provision(SymfonyStyle $io): ?ProvisioningResult
    {
        try {
            return $this->provisioningService->provision();
        } catch (\Throwable $e) {
            $io->error('Provisioning failed: ' . $e->getMessage());

            return null;
        }
    }

    private function reportProvisioning(SymfonyStyle $io, ProvisioningResult $result): void
    {
        if ($result->message !== null) {
            $io->note($result->message);

            return;
        }

        if ($result->provisioned === []) {
            $io->success(sprintf('%s: everything already exists, nothing to do.', $result->backend));

            return;
        }

        $io->success(sprintf(
            '%s: provisioned %d item(s): %s',
            $result->backend,
            count($result->provisioned),
            implode(', ', $result->provisioned),
        ));
    }

    private function showStats(SymfonyStyle $io): int
    {
        $io->title('Search Index Statistics');

        try {
            $stats = $this->indexManagementService->getIndexStats();

            if ($stats['totalDocuments'] === 0) {
                $io->warning('No documents indexed yet');

                return Command::SUCCESS;
            }

            $io->text(sprintf('<info>Total documents:</info> %d', $stats['totalDocuments']));
            $io->newLine();

            foreach ($stats['cores'] as $language => $coreStats) {
                $io->section(sprintf('Index: %s (%s)', $coreStats['core'], $language));
                $io->text(sprintf('  Documents: %d', $coreStats['totalDocuments']));

                if ($coreStats['types'] !== []) {
                    $io->text('  Types:');
                    foreach ($coreStats['types'] as $type => $count) {
                        $io->text(sprintf('    - %s: %d', $type, $count));
                    }
                }

                $io->newLine();
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to fetch stats: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}

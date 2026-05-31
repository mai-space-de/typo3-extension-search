<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Command;

use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mai-search:index',
    description: 'Reindex all content into Solr search indexes',
)]
final class IndexCommand extends Command
{
    public function __construct(
        private readonly IndexManagementService $indexManagementService,
        private readonly SchemaManager $schemaManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'schema-only',
            null,
            InputOption::VALUE_NONE,
            'Only ensure vector field exists in all cores (do not index content)',
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
            return $this->ensureSchema($io);
        }

        return $this->reindexAll($io);
    }

    private function ensureSchema(SymfonyStyle $io): int
    {
        $io->title('Ensuring Solr schema has vector field');

        try {
            $this->schemaManager->ensureVectorFieldExistsInAllCores();
            $io->success('Vector field added to all Solr cores successfully');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to ensure vector field exists: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function reindexAll(SymfonyStyle $io): int
    {
        $io->title('Starting full Solr reindex');

        $io->section('Step 1: Ensuring schema has vector field');
        try {
            $this->schemaManager->ensureVectorFieldExistsInAllCores();
            $io->success('Schema ready');
        } catch (\Throwable $e) {
            $io->error('Schema setup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Step 2: Reindexing all content');
        try {
            $this->indexManagementService->reindexAll();
            $io->success('Reindex completed successfully');

            return $this->showStats($io);
        } catch (\Throwable $e) {
            $io->error('Reindex failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showStats(SymfonyStyle $io): int
    {
        $io->title('Solr Index Statistics');

        try {
            $stats = $this->indexManagementService->getIndexStats();

            if ($stats['totalDocuments'] === 0) {
                $io->warning('No documents indexed yet');
                return Command::SUCCESS;
            }

            $io->text(sprintf('<info>Total documents:</info> %d', $stats['totalDocuments']));
            $io->newLine();

            foreach ($stats['cores'] as $language => $coreStats) {
                $io->section(sprintf('Core: %s (%s)', $coreStats['core'], $language));
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

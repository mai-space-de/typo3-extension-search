<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Command;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Service\ChunkIndexingService;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes RAG chunks (SPEC-v3-caching-chunking.md Phase F) left behind by
 * records DataHandlerHook's queue-based cleanup never saw: hard SQL deletes,
 * disabled extensions, or migration errors. The normal per-save path
 * (AbstractIndexer::removeDocument() → ChunkIndexingService::removeChunks())
 * already handles ordinary editorial deletes — this command is the manual
 * safety net for everything outside that path, analogous to
 * `.lookup/smart-search`'s `smartsearch:cleanup` (SPEC-v3.md 1.4).
 *
 * For each registered indexer, diffs the document UIDs tracked in
 * tx_maisearch_chunks against the indexer's own current live UIDs
 * (getRecordUidsForReindex()) and removes chunks — from both the search
 * backend and the local bookkeeping table — for anything no longer live.
 */
#[AsCommand(
    name: 'mai-search:cleanup-chunks',
    description: 'Remove RAG chunks whose parent record no longer exists',
)]
final class CleanupChunksCommand extends Command
{
    /**
     * Wide enough that getRecordUidsForReindex() never truncates the live set —
     * this command needs the complete live-UID list to diff correctly, not a
     * representative sample.
     */
    private const int BATCH_SIZE = 1_000_000;

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly OrphanChunkCleanupService $chunkRepository,
        private readonly ChunkIndexingService $chunkIndexingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'allow-empty',
            null,
            InputOption::VALUE_NONE,
            'Permit an indexer to report zero live record UIDs, which deletes all of its tracked chunks',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $allowEmpty = (bool) $input->getOption('allow-empty');
        $indexers = $this->indexerRegistry->getAll();

        if ($indexers === []) {
            $io->warning('No indexers registered.');

            return Command::SUCCESS;
        }

        $totalOrphaned = 0;

        foreach ($indexers as $indexer) {
            $type = $indexer->getType();
            $trackedUids = $this->chunkRepository->findDocumentUidsWithChunks($type);

            if ($trackedUids === []) {
                continue;
            }

            $liveUids = $this->resolveLiveUids($indexer);

            if ($liveUids === [] && !$allowEmpty) {
                $io->error(sprintf(
                    'Indexer "%s" reported zero live records — refusing to delete chunks for %d '
                        . 'tracked record(s). Re-run with --allow-empty if the source really is empty.',
                    $type,
                    count($trackedUids),
                ));

                return Command::FAILURE;
            }

            $orphanedUids = array_values(array_diff($trackedUids, $liveUids));

            if ($orphanedUids === []) {
                $io->text(sprintf('%s: no orphaned chunks.', $type));

                continue;
            }

            foreach ($orphanedUids as $uid) {
                $this->chunkIndexingService->removeChunks($type, $uid);
            }

            $io->text(sprintf('%s: removed chunks for %d orphaned record(s).', $type, count($orphanedUids)));
            $totalOrphaned += count($orphanedUids);
        }

        $io->success(sprintf('Cleanup complete. Chunks removed for %d orphaned record(s).', $totalOrphaned));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveLiveUids(SearchIndexerInterface $indexer): array
    {
        $context = new IndexingContext(core: '', batchSize: self::BATCH_SIZE);

        $uids = [];
        foreach ($indexer->getRecordUidsForReindex($context) as $uid) {
            $uids[] = (int) $uid;
        }

        return $uids;
    }
}

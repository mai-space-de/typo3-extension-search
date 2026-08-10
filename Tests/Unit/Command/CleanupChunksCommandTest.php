<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Command;

use Maispace\MaiSearch\Command\CleanupChunksCommand;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Service\ChunkIndexingService;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupChunksCommandTest extends TestCase
{
    private IndexerRegistry&MockObject $indexerRegistry;
    private OrphanChunkCleanupService&MockObject $chunkRepository;
    private ChunkIndexingService&MockObject $chunkIndexingService;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->chunkRepository = $this->createMock(OrphanChunkCleanupService::class);
        $this->chunkIndexingService = $this->createMock(ChunkIndexingService::class);

        $this->tester = new CommandTester(new CleanupChunksCommand(
            $this->indexerRegistry,
            $this->chunkRepository,
            $this->chunkIndexingService,
        ));
    }

    private function makeIndexer(string $type, array $liveUids): SearchIndexerInterface&MockObject
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->method('getType')->willReturn($type);
        $indexer->method('getRecordUidsForReindex')->willReturn($liveUids);

        return $indexer;
    }

    #[Test]
    public function warnsWhenNoIndexersAreRegistered(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([]);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No indexers registered', $this->tester->getDisplay());
    }

    #[Test]
    public function skipsIndexerWithNoTrackedChunks(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([$this->makeIndexer('news', [])]);
        $this->chunkRepository->method('findDocumentUidsWithChunks')->with('news')->willReturn([]);

        $this->chunkIndexingService->expects(self::never())->method('removeChunks');

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function reportsNoOrphansWhenAllTrackedRecordsAreStillLive(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([$this->makeIndexer('news', [1, 2])]);
        $this->chunkRepository->method('findDocumentUidsWithChunks')->willReturn([1, 2]);

        $this->chunkIndexingService->expects(self::never())->method('removeChunks');

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('no orphaned chunks', $this->tester->getDisplay());
    }

    #[Test]
    public function removesChunksForRecordsNoLongerLive(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([$this->makeIndexer('news', [1, 3])]);
        $this->chunkRepository->method('findDocumentUidsWithChunks')->willReturn([1, 2, 3]);

        $this->chunkIndexingService->expects(self::once())->method('removeChunks')->with('news', 2);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('removed chunks for 1 orphaned record', $this->tester->getDisplay());
    }

    #[Test]
    public function refusesToDeleteWhenLiveUidsAreEmptyWithoutAllowEmpty(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([$this->makeIndexer('news', [])]);
        $this->chunkRepository->method('findDocumentUidsWithChunks')->willReturn([1]);

        $this->chunkIndexingService->expects(self::never())->method('removeChunks');

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--allow-empty', $this->tester->getDisplay());
    }

    #[Test]
    public function deletesAllTrackedChunksWhenAllowEmptyIsPassed(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([$this->makeIndexer('news', [])]);
        $this->chunkRepository->method('findDocumentUidsWithChunks')->willReturn([1]);

        $this->chunkIndexingService->expects(self::once())->method('removeChunks')->with('news', 1);

        $exitCode = $this->tester->execute(['--allow-empty' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function processesEveryRegisteredIndexerIndependently(): void
    {
        $this->indexerRegistry->method('getAll')->willReturn([
            $this->makeIndexer('news', [1]),
            $this->makeIndexer('faq', [5]),
        ]);
        $this->chunkRepository->method('findDocumentUidsWithChunks')->willReturnMap([
            ['news', [1, 2]],
            ['faq', [5]],
        ]);

        $this->chunkIndexingService->expects(self::once())->method('removeChunks')->with('news', 2);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }
}

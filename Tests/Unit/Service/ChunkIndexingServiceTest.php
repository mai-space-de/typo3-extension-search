<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\ChunkSearchBackendInterface;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Service\BackendRegistry;
use Maispace\MaiSearch\Service\ChunkIndexingService;
use Maispace\MaiSearch\Service\ContentChunkerService;
use Maispace\MaiSearch\Service\ContentHashService;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ChunkIndexingServiceTest extends TestCase
{
    private OrphanChunkCleanupService&MockObject $chunkRepository;
    private BackendRegistry&MockObject $backendRegistry;
    private ChunkIndexingService $subject;

    protected function setUp(): void
    {
        $this->chunkRepository = $this->createMock(OrphanChunkCleanupService::class);
        $this->backendRegistry = $this->createMock(BackendRegistry::class);

        $ragConfiguration = $this->createMock(RagConfiguration::class);
        $ragConfiguration->method('getChunkSize')->willReturn(500);
        $ragConfiguration->method('getChunkOverlap')->willReturn(50);

        $this->subject = new ChunkIndexingService(
            new ContentChunkerService(),
            new ContentHashService($this->createMock(ConnectionPool::class)),
            $this->chunkRepository,
            $this->backendRegistry,
            $ragConfiguration,
        );
    }

    private function makeDocument(string $content = 'Hello world.'): SearchDocumentInput
    {
        return new SearchDocumentInput(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: $content,
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );
    }

    /**
     * @return (SearchBackendInterface&ChunkSearchBackendInterface&MockObject)
     */
    private function makeChunkBackend(): SearchBackendInterface&ChunkSearchBackendInterface&MockObject
    {
        return $this->createMockForIntersectionOfInterfaces([
            SearchBackendInterface::class,
            ChunkSearchBackendInterface::class,
        ]);
    }

    #[Test]
    public function indexChunksDoesNothingWhenActiveBackendDoesNotSupportChunks(): void
    {
        $backend = $this->createMock(SearchBackendInterface::class);
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->expects(self::never())->method('hasChunkChanged');
        $this->chunkRepository->expects(self::never())->method('findOrphanChunkIndices');

        $this->subject->indexChunks($this->makeDocument());
    }

    #[Test]
    public function indexChunksSkipsUnchangedChunks(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->method('hasChunkChanged')->willReturn(false);
        $this->chunkRepository->method('findOrphanChunkIndices')->willReturn([]);

        $backend->expects(self::never())->method('indexChunk');
        $this->chunkRepository->expects(self::never())->method('setChunkHash');

        $this->subject->indexChunks($this->makeDocument());
    }

    #[Test]
    public function indexChunksIndexesAChangedChunkAndStoresItsHash(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->method('hasChunkChanged')->willReturn(true);
        $this->chunkRepository->method('findOrphanChunkIndices')->willReturn([]);

        $backend
            ->expects(self::once())
            ->method('indexChunk')
            ->with(self::callback(static function (ChunkDocumentInput $chunk): bool {
                self::assertSame('news', $chunk->parentType);
                self::assertSame(1, $chunk->parentUid);
                self::assertSame('Title', $chunk->parentTitle);
                self::assertSame('/news/1', $chunk->parentUrl);
                self::assertSame(0, $chunk->chunkIndex);
                self::assertSame('Hello world.', $chunk->content);

                return true;
            }));

        $this->chunkRepository
            ->expects(self::once())
            ->method('setChunkHash')
            ->with('news', 1, 0, hash('sha256', 'Hello world.'), 'Hello world.');

        $this->subject->indexChunks($this->makeDocument());
    }

    #[Test]
    public function indexChunksDoesNotStoreHashWhenBackendThrows(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->method('hasChunkChanged')->willReturn(true);
        $this->chunkRepository->method('findOrphanChunkIndices')->willReturn([]);

        $backend->method('indexChunk')->willThrowException(new \RuntimeException('Solr unreachable'));
        $this->chunkRepository->expects(self::never())->method('setChunkHash');

        // Must not propagate — a chunk write failure can't fail the whole document.
        $this->subject->indexChunks($this->makeDocument());
    }

    #[Test]
    public function indexChunksRemovesOrphanChunksFromBackendAndLocalTable(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->method('hasChunkChanged')->willReturn(false);
        $this->chunkRepository->method('findOrphanChunkIndices')->with('news', 1, 1)->willReturn([2, 3]);

        $backend
            ->expects(self::exactly(2))
            ->method('removeChunk')
            ->with('news', 1, self::logicalOr(2, 3));

        $this->chunkRepository->expects(self::once())->method('cleanupOrphans')->with('news', 1, 1);

        $this->subject->indexChunks($this->makeDocument());
    }

    #[Test]
    public function indexChunksSkipsCleanupCallWhenNothingIsOrphaned(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->method('hasChunkChanged')->willReturn(false);
        $this->chunkRepository->method('findOrphanChunkIndices')->willReturn([]);

        $backend->expects(self::never())->method('removeChunk');
        $this->chunkRepository->expects(self::never())->method('cleanupOrphans');

        $this->subject->indexChunks($this->makeDocument());
    }

    #[Test]
    public function indexChunksWithEmptyContentRemovesAllExistingChunks(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->method('findOrphanChunkIndices')->with('news', 1, 0)->willReturn([0]);

        $backend->expects(self::never())->method('indexChunk');
        $backend->expects(self::once())->method('removeChunk')->with('news', 1, 0);
        $this->chunkRepository->expects(self::once())->method('cleanupOrphans')->with('news', 1, 0);

        $this->subject->indexChunks($this->makeDocument(''));
    }

    #[Test]
    public function removeChunksDoesNothingWhenActiveBackendDoesNotSupportChunks(): void
    {
        $backend = $this->createMock(SearchBackendInterface::class);
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->chunkRepository->expects(self::never())->method('removeAllChunks');

        $this->subject->removeChunks('news', 1);
    }

    #[Test]
    public function removeChunksDelegatesToBackendAndLocalRepository(): void
    {
        $backend = $this->makeChunkBackend();
        $this->backendRegistry->method('getActive')->willReturn($backend);

        $backend->expects(self::once())->method('removeChunks')->with('news', 1);
        $this->chunkRepository->expects(self::once())->method('removeAllChunks')->with('news', 1);

        $this->subject->removeChunks('news', 1);
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Service\ChunkSearchBackendInterface;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Reranking\RerankerInterface;
use Maispace\MaiSearch\Service\BackendRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RagContextResolverTest extends TestCase
{
    private SearchService&MockObject $searchService;

    private RerankerInterface&MockObject $reranker;

    private BackendRegistry&MockObject $backendRegistry;

    private RagContextResolver $resolver;

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);
        $this->reranker = $this->createMock(RerankerInterface::class);
        $this->backendRegistry = $this->createMock(BackendRegistry::class);
        // Default: active backend does not support chunking, so existing tests
        // exercise the pre-Phase-D whole-document fallback path unchanged.
        $this->backendRegistry->method('getActive')->willReturn($this->createMock(SearchBackendInterface::class));
        $this->resolver = new RagContextResolver($this->searchService, $this->reranker, $this->backendRegistry);
    }

    private function makeResult(string $title): SearchResult
    {
        return new SearchResult('page', $title, 'snippet', 'https://example.com/' . $title, 'icon', null, 1.0);
    }

    #[Test]
    public function queriesFromOffsetZeroWithRagEnabledRegardlessOfPagination(): void
    {
        $this->searchService
            ->expects(self::once())
            ->method('search')
            ->with('query', 20, 0, null, true, 'news')
            ->willReturn(new SearchResultPage([], 0));

        $this->resolver->resolve('query', null, 'news', 5, 20);
    }

    #[Test]
    public function widensToTheLargerOfRerankWidthAndTopN(): void
    {
        $this->searchService
            ->expects(self::once())
            ->method('search')
            ->with(self::anything(), 30, self::anything(), self::anything(), self::anything(), self::anything())
            ->willReturn(new SearchResultPage([], 0));

        $this->resolver->resolve('query', null, null, 30, 20);
    }

    #[Test]
    public function returnsEmptyArrayWithoutRerankingWhenNoCandidatesFound(): void
    {
        $this->searchService->method('search')->willReturn(new SearchResultPage([], 0));
        $this->reranker->expects(self::never())->method('rerank');

        self::assertSame([], $this->resolver->resolve('query', null, null, 5));
    }

    #[Test]
    public function delegatesRerankingToTheConfiguredReranker(): void
    {
        $candidates = [$this->makeResult('a'), $this->makeResult('b')];
        $this->searchService->method('search')->willReturn(new SearchResultPage($candidates, 2));

        $reranked = [$candidates[1]];
        $this->reranker
            ->expects(self::once())
            ->method('rerank')
            ->with('query', $candidates, 1)
            ->willReturn($reranked);

        self::assertSame($reranked, $this->resolver->resolve('query', null, null, 1));
    }

    // ── chunk-based retrieval (SPEC-v3-caching-chunking.md Phase D) ─────────────

    #[Test]
    public function usesChunkSearchWhenTheActiveBackendSupportsIt(): void
    {
        $chunkBackend = $this->createMockForIntersectionOfInterfaces([
            SearchBackendInterface::class,
            ChunkSearchBackendInterface::class,
        ]);
        $chunkCandidates = [$this->makeResult('chunk-a'), $this->makeResult('chunk-b')];
        $chunkBackend
            ->expects(self::once())
            ->method('searchChunks')
            ->with('query', null, 'news', 20)
            ->willReturn($chunkCandidates);

        $backendRegistry = $this->createMock(BackendRegistry::class);
        $backendRegistry->method('getActive')->willReturn($chunkBackend);
        $resolver = new RagContextResolver($this->searchService, $this->reranker, $backendRegistry);

        $this->searchService->expects(self::never())->method('search');
        $this->reranker->method('rerank')->willReturn($chunkCandidates);

        $resolver->resolve('query', null, 'news', 5, 20);
    }

    #[Test]
    public function fallsBackToWholeDocumentSearchWhenTheActiveBackendDoesNotSupportChunking(): void
    {
        $this->searchService
            ->expects(self::once())
            ->method('search')
            ->with('query', 20, 0, null, true, null)
            ->willReturn(new SearchResultPage([], 0));

        $this->resolver->resolve('query', null, null, 5, 20);
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Reranking\RerankerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RagContextResolverTest extends TestCase
{
    private SearchService&MockObject $searchService;

    private RerankerInterface&MockObject $reranker;

    private RagContextResolver $resolver;

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);
        $this->reranker = $this->createMock(RerankerInterface::class);
        $this->resolver = new RagContextResolver($this->searchService, $this->reranker);
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
}

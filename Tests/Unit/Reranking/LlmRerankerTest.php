<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Reranking;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\ChatResponse;
use Maispace\MaiSearch\Domain\Service\ChatProviderInterface;
use Maispace\MaiSearch\Reranking\LlmReranker;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LlmRerankerTest extends TestCase
{
    private ProviderRegistry&MockObject $providerRegistry;

    private ChatProviderInterface&MockObject $chatProvider;

    protected function setUp(): void
    {
        $this->chatProvider = $this->createMock(ChatProviderInterface::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($this->chatProvider);
    }

    private function makeResult(string $title, float $score = 1.0): SearchResult
    {
        return new SearchResult('page', $title, 'snippet for ' . $title, 'https://example.com/' . $title, 'icon', null, $score);
    }

    #[Test]
    public function returnsAllCandidatesUnchangedWhenCountIsAtOrBelowTopN(): void
    {
        $this->chatProvider->expects(self::never())->method('complete');

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a'), $this->makeResult('b')];

        self::assertSame($candidates, $reranker->rerank('query', $candidates, 5));
    }

    #[Test]
    public function reordersCandidatesAccordingToModelResponse(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('[2, 0, 1]'));

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a'), $this->makeResult('b'), $this->makeResult('c')];

        $result = $reranker->rerank('query', $candidates, 2);

        self::assertSame(['c', 'a'], array_map(static fn(SearchResult $r): string => $r->title, $result));
    }

    #[Test]
    public function preservesOriginalScoreOnReorderedResults(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('[1, 0]'));

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a', 0.5), $this->makeResult('b', 0.9), $this->makeResult('c', 0.1)];

        $result = $reranker->rerank('query', $candidates, 2);

        self::assertSame(0.9, $result[0]->score);
        self::assertSame(0.5, $result[1]->score);
    }

    #[Test]
    public function appendsCandidatesOmittedByTheModelInOriginalOrder(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('[2]'));

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a'), $this->makeResult('b'), $this->makeResult('c')];

        $result = $reranker->rerank('query', $candidates, 3);

        self::assertSame(['c', 'a', 'b'], array_map(static fn(SearchResult $r): string => $r->title, $result));
    }

    #[Test]
    public function fallsBackToOriginalOrderWhenProviderThrows(): void
    {
        $this->chatProvider->method('complete')->willThrowException(new \RuntimeException('unreachable'));

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a'), $this->makeResult('b'), $this->makeResult('c')];

        $result = $reranker->rerank('query', $candidates, 2);

        self::assertSame(['a', 'b'], array_map(static fn(SearchResult $r): string => $r->title, $result));
    }

    #[Test]
    public function fallsBackToOriginalOrderWhenResponseHasNoJsonArray(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('I cannot help with that.'));

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a'), $this->makeResult('b'), $this->makeResult('c')];

        $result = $reranker->rerank('query', $candidates, 2);

        self::assertSame(['a', 'b'], array_map(static fn(SearchResult $r): string => $r->title, $result));
    }

    #[Test]
    public function ignoresOutOfRangeIndicesFromTheModel(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('[99, 0, -1]'));

        $reranker = new LlmReranker($this->providerRegistry);
        $candidates = [$this->makeResult('a'), $this->makeResult('b'), $this->makeResult('c')];

        $result = $reranker->rerank('query', $candidates, 3);

        self::assertSame(['a', 'b', 'c'], array_map(static fn(SearchResult $r): string => $r->title, $result));
    }

    #[Test]
    public function returnsEmptyArrayForEmptyCandidates(): void
    {
        $reranker = new LlmReranker($this->providerRegistry);

        self::assertSame([], $reranker->rerank('query', [], 5));
    }
}

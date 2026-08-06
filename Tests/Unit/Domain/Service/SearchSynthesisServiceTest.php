<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SourceReference;
use Maispace\MaiSearch\Domain\Dto\SynthesisResult;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Domain\Model\ChatResponse;
use Maispace\MaiSearch\Domain\Service\ChatProviderInterface;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SearchSynthesisServiceTest extends TestCase
{
    private ProviderRegistry&MockObject $providerRegistry;
    private ChatProviderInterface&MockObject $chatProvider;
    private SearchSynthesisService $service;

    protected function setUp(): void
    {
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->chatProvider = $this->createMock(ChatProviderInterface::class);
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($this->chatProvider);
        $this->service = new SearchSynthesisService($this->providerRegistry);
    }

    // ── disabled-flag / empty-context short-circuits ─────────────────────────

    #[Test]
    public function synthesiseReturnsEmptyResultWhenRagDisabled(): void
    {
        $this->chatProvider->expects(self::never())->method('complete');

        $result = $this->service->synthesise('query', [$this->makeResult()], false);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('', $result->answer);
        self::assertSame([], $result->sources);
    }

    #[Test]
    public function synthesiseReturnsEmptyResultForEmptyContext(): void
    {
        $this->chatProvider->expects(self::never())->method('complete');

        $result = $this->service->synthesise('query', [], true);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('', $result->answer);
        self::assertSame([], $result->sources);
    }

    // ── prompt assembly ──────────────────────────────────────────────────────

    #[Test]
    public function synthesiseIncludesQueryInUserPrompt(): void
    {
        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request): bool {
                return str_contains($request->messages[1]->content, 'What is TYPO3?');
            }))
            ->willReturn(new ChatResponse('answer'));

        $this->service->synthesise('What is TYPO3?', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseIncludesContextTitleAndSnippetInPrompt(): void
    {
        $context = [
            $this->makeResult(title: 'TYPO3 Introduction', snippet: 'TYPO3 is an open-source CMS.'),
        ];

        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request) use ($context): bool {
                $userMessage = $request->messages[1]->content;

                return str_contains($userMessage, 'TYPO3 Introduction')
                    && str_contains($userMessage, 'TYPO3 is an open-source CMS.');
            }))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('What is TYPO3?', $context, true);
    }

    #[Test]
    public function synthesiseIncludesAllContextItemsNumberedSequentially(): void
    {
        $context = [
            $this->makeResult(title: 'First Result', snippet: 'Snippet one'),
            $this->makeResult(title: 'Second Result', snippet: 'Snippet two'),
            $this->makeResult(title: 'Third Result', snippet: 'Snippet three'),
        ];

        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request): bool {
                $userMessage = $request->messages[1]->content;

                return str_contains($userMessage, '[1]')
                    && str_contains($userMessage, '[2]')
                    && str_contains($userMessage, '[3]');
            }))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('test query', $context, true);
    }

    #[Test]
    public function synthesiseFallsBackToUrlWhenTitleIsEmpty(): void
    {
        $context = [
            $this->makeResult(title: '', snippet: 'some snippet', url: '/fallback-url'),
        ];

        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request): bool {
                return str_contains($request->messages[1]->content, '/fallback-url');
            }))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('query', $context, true);
    }

    #[Test]
    public function synthesiseUsesSystemPromptAsFirstMessage(): void
    {
        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static fn(ChatRequest $request): bool => $request->messages[0]->role === 'system'))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('query', [$this->makeResult()], true);
    }

    // ── answer / sources ─────────────────────────────────────────────────────

    #[Test]
    public function synthesiseReturnsAnswerFromActiveChatProvider(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('TYPO3 is a free, open-source CMS.'));

        $result = $this->service->synthesise('What is TYPO3?', [$this->makeResult()], true);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('TYPO3 is a free, open-source CMS.', $result->answer);
    }

    #[Test]
    public function synthesiseReturnsSourceReferencesForContext(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('Answer text.'));

        $context = [
            $this->makeResult(title: 'First', snippet: 'Snippet 1', url: '/first', type: 'news'),
            $this->makeResult(title: 'Second', snippet: 'Snippet 2', url: '/second', type: 'page'),
        ];

        $result = $this->service->synthesise('query', $context, true);

        self::assertCount(2, $result->sources);

        self::assertInstanceOf(SourceReference::class, $result->sources[0]);
        self::assertSame('First', $result->sources[0]->title);
        self::assertSame('/first', $result->sources[0]->url);
        self::assertSame('news', $result->sources[0]->type);
        self::assertSame(0.9, $result->sources[0]->score);

        self::assertInstanceOf(SourceReference::class, $result->sources[1]);
        self::assertSame('Second', $result->sources[1]->title);
        self::assertSame('/second', $result->sources[1]->url);
        self::assertSame('page', $result->sources[1]->type);
    }

    #[Test]
    public function synthesiseSourceReferencesPreserveAllContextItems(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('ok'));

        $context = [
            $this->makeResult(title: 'A', url: '/a', type: 'news'),
            $this->makeResult(title: 'B', url: '/b', type: 'faq'),
            $this->makeResult(title: 'C', url: '/c', type: 'events'),
        ];

        $result = $this->service->synthesise('query', $context, true);

        self::assertCount(3, $result->sources);
        self::assertSame('/a', $result->sources[0]->url);
        self::assertSame('/b', $result->sources[1]->url);
        self::assertSame('/c', $result->sources[2]->url);
    }

    #[Test]
    public function emptySynthesisResultHasEmptyAnswerAndNoSources(): void
    {
        $empty = SynthesisResult::empty();

        self::assertSame('', $empty->answer);
        self::assertSame([], $empty->sources);
    }

    private function makeResult(
        string $title = 'Test Title',
        string $snippet = 'Test snippet content',
        string $url = '/test-url',
        string $type = 'page',
    ): SearchResult {
        return new SearchResult(
            type: $type,
            title: $title,
            snippet: $snippet,
            url: $url,
            icon: 'page-icon',
            date: null,
            score: 0.9,
        );
    }
}

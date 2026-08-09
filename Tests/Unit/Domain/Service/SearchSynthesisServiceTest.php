<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SourceReference;
use Maispace\MaiSearch\Domain\Dto\SynthesisResult;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Domain\Model\ChatResponse;
use Maispace\MaiSearch\Domain\Model\ConversationHistory;
use Maispace\MaiSearch\Domain\Service\ChatProviderInterface;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

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

        // AnswerCacheService is final and can't be mocked directly — wrap a mocked
        // cache frontend instead, always missing so existing behavior (always call
        // the provider) is preserved for tests that don't cover caching itself.
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $answerCacheService = new AnswerCacheService($cache);

        $this->service = new SearchSynthesisService($this->providerRegistry, $answerCacheService);
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

    // ── conversation history ─────────────────────────────────────────────────

    #[Test]
    public function synthesiseInsertsHistoryBetweenSystemMessageAndCurrentQuestion(): void
    {
        $history = ConversationHistory::empty()
            ->withUserMessage('What are the opening hours?')
            ->withAssistantMessage('8:00 to 20:00.');

        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request): bool {
                return $request->messages[0]->role === 'system'
                    && $request->messages[1]->role === 'user'
                    && $request->messages[1]->content === 'What are the opening hours?'
                    && $request->messages[2]->role === 'assistant'
                    && $request->messages[2]->content === '8:00 to 20:00.'
                    && $request->messages[3]->role === 'user'
                    && str_contains($request->messages[3]->content, 'And on weekends?');
            }))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('And on weekends?', [$this->makeResult()], true, $history);
    }

    #[Test]
    public function synthesiseWorksWithoutHistoryArgument(): void
    {
        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request): bool {
                return count($request->messages) === 2;
            }))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('query', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseIgnoresEmptyHistory(): void
    {
        $this->chatProvider
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(static function (ChatRequest $request): bool {
                return count($request->messages) === 2;
            }))
            ->willReturn(new ChatResponse('ok'));

        $this->service->synthesise('query', [$this->makeResult()], true, ConversationHistory::empty());
    }

    #[Test]
    public function buildChatRequestProducesTheSamePromptSynthesiseSends(): void
    {
        $context = [$this->makeResult(title: 'A', snippet: 'B')];
        $history = ConversationHistory::empty()->withUserMessage('prior question');

        $request = $this->service->buildChatRequest('query', $context, $history);

        self::assertSame('system', $request->messages[0]->role);
        self::assertSame('user', $request->messages[1]->role);
        self::assertSame('prior question', $request->messages[1]->content);
        self::assertSame('user', $request->messages[2]->role);
        self::assertStringContainsString('query', $request->messages[2]->content);
    }

    // ── answer cache (SPEC-v2.md Phase 7) ─────────────────────────────────────

    #[Test]
    public function synthesiseReturnsCachedAnswerWithoutCallingProviderOnCacheHit(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn('cached answer');
        $service = new SearchSynthesisService($this->providerRegistry, new AnswerCacheService($cache));

        $this->chatProvider->expects(self::never())->method('complete');

        $result = $service->synthesise('query', [$this->makeResult(url: '/a')], true);

        self::assertSame('cached answer', $result->answer);
    }

    #[Test]
    public function synthesiseStillReturnsSourcesOnCacheHit(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn('cached answer');
        $service = new SearchSynthesisService($this->providerRegistry, new AnswerCacheService($cache));

        $result = $service->synthesise('query', [$this->makeResult(title: 'A', url: '/a')], true);

        self::assertCount(1, $result->sources);
        self::assertSame('A', $result->sources[0]->title);
    }

    #[Test]
    public function synthesiseCallsProviderAndStoresResultOnCacheMiss(): void
    {
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('fresh answer'));

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::once())->method('set')->with(
            self::isType('string'),
            'fresh answer',
            self::anything(),
            self::anything(),
        );
        $service = new SearchSynthesisService($this->providerRegistry, new AnswerCacheService($cache));

        $result = $service->synthesise('query', [$this->makeResult(url: '/a')], true);

        self::assertSame('fresh answer', $result->answer);
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

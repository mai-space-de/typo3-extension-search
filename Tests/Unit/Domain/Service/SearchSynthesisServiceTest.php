<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SourceReference;
use Maispace\MaiSearch\Domain\Dto\SynthesisResult;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

final class SearchSynthesisServiceTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    // ── disabled-flag short-circuit ──────────────────────────────────────────

    #[Test]
    public function synthesiseReturnsEmptyResultWhenRagDisabled(): void
    {
        $this->requestFactory->expects(self::never())->method('request');

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $result = $service->synthesise('query', [$this->makeResult()], false);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('', $result->answer);
        self::assertSame([], $result->sources);
    }

    // ── empty-context fallback ───────────────────────────────────────────────

    #[Test]
    public function synthesiseReturnsEmptyResultForEmptyContext(): void
    {
        $this->requestFactory->expects(self::never())->method('request');

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $result = $service->synthesise('query', [], true);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('', $result->answer);
        self::assertSame([], $result->sources);
    }

    #[Test]
    public function synthesiseReturnsEmptyResultWhenApiKeyIsEmpty(): void
    {
        $this->requestFactory->expects(self::never())->method('request');

        $service = new SearchSynthesisService($this->requestFactory, '');
        $result = $service->synthesise('query', [$this->makeResult()], true);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('', $result->answer);
        self::assertSame([], $result->sources);
    }

    // ── prompt assembly ──────────────────────────────────────────────────────

    #[Test]
    public function synthesiseIncludesQueryInUserPrompt(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"answer"}}]}'));

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('api.openai.com'),
                'POST',
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    $userMessage = $body['messages'][1]['content'] ?? '';
                    return str_contains($userMessage, 'What is TYPO3?');
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $service->synthesise('What is TYPO3?', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseIncludesContextChunkTitleAndSnippetInPrompt(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"ok"}}]}'));

        $context = [
            $this->makeResult(title: 'TYPO3 Introduction', snippet: 'TYPO3 is an open-source CMS.'),
        ];

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    $userMessage = $body['messages'][1]['content'] ?? '';
                    return str_contains($userMessage, 'TYPO3 Introduction')
                        && str_contains($userMessage, 'TYPO3 is an open-source CMS.');
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $service->synthesise('What is TYPO3?', $context, true);
    }

    #[Test]
    public function synthesiseIncludesAllContextChunksNumberedSequentially(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"ok"}}]}'));

        $context = [
            $this->makeResult(title: 'First Result', snippet: 'Snippet one'),
            $this->makeResult(title: 'Second Result', snippet: 'Snippet two'),
            $this->makeResult(title: 'Third Result', snippet: 'Snippet three'),
        ];

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    $userMessage = $body['messages'][1]['content'] ?? '';
                    return str_contains($userMessage, '[1]')
                        && str_contains($userMessage, '[2]')
                        && str_contains($userMessage, '[3]');
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $service->synthesise('test query', $context, true);
    }

    #[Test]
    public function synthesiseFallsBackToUrlWhenTitleIsEmpty(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"ok"}}]}'));

        $context = [
            $this->makeResult(title: '', snippet: 'some snippet', url: '/fallback-url'),
        ];

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    $userMessage = $body['messages'][1]['content'] ?? '';
                    return str_contains($userMessage, '/fallback-url');
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $service->synthesise('query', $context, true);
    }

    // ── API call mechanics ───────────────────────────────────────────────────

    #[Test]
    public function synthesisePassesBearerTokenInAuthorizationHeader(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"ok"}}]}'));

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('api.openai.com'),
                'POST',
                self::callback(static function (array $options): bool {
                    return ($options['headers']['Authorization'] ?? '') === 'Bearer sk-mykey';
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-mykey');
        $service->synthesise('query', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseUsesDefaultModelGpt4oMini(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"ok"}}]}'));

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return ($body['model'] ?? '') === 'gpt-4o-mini';
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $service->synthesise('query', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseUsesConfiguredModel(): void
    {
        $response = $this->makeResponseMock(200, $this->makeBodyMock('{"choices":[{"message":{"content":"ok"}}]}'));

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return ($body['model'] ?? '') === 'gpt-4o';
                }),
            )
            ->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test', 'gpt-4o');
        $service->synthesise('query', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseReturnsSynthesisedAnswerFromApiResponse(): void
    {
        $body = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'TYPO3 is a free, open-source CMS.']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $body);

        $this->requestFactory->method('request')->willReturn($response);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $result = $service->synthesise('What is TYPO3?', [$this->makeResult()], true);

        self::assertInstanceOf(SynthesisResult::class, $result);
        self::assertSame('TYPO3 is a free, open-source CMS.', $result->answer);
    }

    // ── source references ────────────────────────────────────────────────────

    #[Test]
    public function synthesiseReturnsSourceReferencesForContext(): void
    {
        $body = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'Answer text.']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $body);

        $this->requestFactory->method('request')->willReturn($response);

        $context = [
            $this->makeResult(title: 'First', snippet: 'Snippet 1', url: '/first', type: 'news'),
            $this->makeResult(title: 'Second', snippet: 'Snippet 2', url: '/second', type: 'page'),
        ];

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $result = $service->synthesise('query', $context, true);

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
        $body = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $body);

        $this->requestFactory->method('request')->willReturn($response);

        $context = [
            $this->makeResult(title: 'A', url: '/a', type: 'news'),
            $this->makeResult(title: 'B', url: '/b', type: 'faq'),
            $this->makeResult(title: 'C', url: '/c', type: 'events'),
        ];

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $result = $service->synthesise('query', $context, true);

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

    // ── error handling ───────────────────────────────────────────────────────

    #[Test]
    public function synthesiseThrowsRuntimeExceptionOnNon200Response(): void
    {
        $body = $this->makeBodyMock('{"error":{"message":"Invalid API key"}}');
        $response = $this->makeResponseMock(401, $body);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $service = new SearchSynthesisService($this->requestFactory, 'bad-key');
        $service->synthesise('query', [$this->makeResult()], true);
    }

    #[Test]
    public function synthesiseThrowsRuntimeExceptionOn429Response(): void
    {
        $body = $this->makeBodyMock('{"error":{"message":"Rate limit exceeded"}}');
        $response = $this->makeResponseMock(429, $body);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);

        $service = new SearchSynthesisService($this->requestFactory, 'sk-test');
        $service->synthesise('query', [$this->makeResult()], true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function makeBodyMock(string $contents): StreamInterface&MockObject
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('getContents')->willReturn($contents);
        return $body;
    }

    private function makeResponseMock(int $statusCode, StreamInterface $body): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($body);
        return $response;
    }
}

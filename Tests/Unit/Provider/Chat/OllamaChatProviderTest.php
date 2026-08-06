<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Provider\Chat;

use GuzzleHttp\Psr7\Utils;
use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Provider\Chat\OllamaChatProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OllamaChatProviderTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    private function ndjsonResponse(string $ndjson): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn(Utils::streamFor($ndjson));

        return $response;
    }

    #[Test]
    public function getIdentifierReturnsOllama(): void
    {
        $provider = new OllamaChatProvider($this->requestFactory);

        self::assertSame('ollama', $provider->getIdentifier());
    }

    #[Test]
    public function streamYieldsTokensFromNdjsonLines(): void
    {
        $ndjson = json_encode(['message' => ['content' => 'Hello']], JSON_THROW_ON_ERROR) . "\n"
            . json_encode(['message' => ['content' => ' world']], JSON_THROW_ON_ERROR) . "\n";

        $this->requestFactory->method('request')->willReturn($this->ndjsonResponse($ndjson));

        $provider = new OllamaChatProvider($this->requestFactory);
        $tokens = iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));

        self::assertSame(['Hello', ' world'], $tokens);
    }

    #[Test]
    public function streamPostsToConfiguredBaseUrlChatEndpoint(): void
    {
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with('http://ollama.local:11434/api/chat', 'POST', self::anything())
            ->willReturn($this->ndjsonResponse(''));

        $provider = new OllamaChatProvider($this->requestFactory, 'http://ollama.local:11434/');
        iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));
    }

    #[Test]
    public function completeAggregatesStreamedTokens(): void
    {
        $ndjson = json_encode(['message' => ['content' => 'foo']], JSON_THROW_ON_ERROR) . "\n"
            . json_encode(['message' => ['content' => 'bar']], JSON_THROW_ON_ERROR) . "\n";

        $this->requestFactory->method('request')->willReturn($this->ndjsonResponse($ndjson));

        $provider = new OllamaChatProvider($this->requestFactory);
        $response = $provider->complete(new ChatRequest([new ChatMessage('user', 'Hi')]));

        self::assertSame('foobar', $response->content);
    }
}

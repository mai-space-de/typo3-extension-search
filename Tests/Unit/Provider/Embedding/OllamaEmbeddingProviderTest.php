<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Provider\Embedding;

use Maispace\MaiSearch\Provider\Embedding\OllamaEmbeddingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OllamaEmbeddingProviderTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    private function makeResponseMock(int $statusCode, string $body): ResponseInterface&MockObject
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    #[Test]
    public function getIdentifierReturnsOllama(): void
    {
        $provider = new OllamaEmbeddingProvider($this->requestFactory);

        self::assertSame('ollama', $provider->getIdentifier());
    }

    #[Test]
    public function embedReturnsEmptyArrayForEmptyText(): void
    {
        $this->requestFactory->expects(self::never())->method('request');

        $provider = new OllamaEmbeddingProvider($this->requestFactory);

        self::assertSame([], $provider->embed(''));
    }

    #[Test]
    public function embedPostsToConfiguredBaseUrl(): void
    {
        $this->requestFactory
            ->method('request')
            ->with('http://ollama.local:11434/api/embeddings', 'POST', self::anything())
            ->willReturn($this->makeResponseMock(200, json_encode(['embedding' => [0.4, 0.5]], JSON_THROW_ON_ERROR)));

        $provider = new OllamaEmbeddingProvider($this->requestFactory, 'http://ollama.local:11434/');

        self::assertSame([0.4, 0.5], $provider->embed('text'));
    }

    #[Test]
    public function embedThrowsOnNon200Response(): void
    {
        $this->requestFactory->method('request')->willReturn($this->makeResponseMock(500, '{}'));

        $provider = new OllamaEmbeddingProvider($this->requestFactory);

        $this->expectException(\RuntimeException::class);
        $provider->embed('text');
    }

    #[Test]
    public function getMaxInputTokensReturns8192(): void
    {
        $provider = new OllamaEmbeddingProvider($this->requestFactory);

        self::assertSame(8192, $provider->getMaxInputTokens());
    }
}

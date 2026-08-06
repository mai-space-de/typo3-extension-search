<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Provider\Embedding;

use Maispace\MaiSearch\Provider\Embedding\OpenAiEmbeddingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiEmbeddingProviderTest extends TestCase
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
    public function getIdentifierReturnsOpenai(): void
    {
        $provider = new OpenAiEmbeddingProvider($this->requestFactory, 'sk-test');

        self::assertSame('openai', $provider->getIdentifier());
    }

    #[Test]
    public function embedReturnsEmptyArrayWhenApiKeyIsEmpty(): void
    {
        $this->requestFactory->expects(self::never())->method('request');

        $provider = new OpenAiEmbeddingProvider($this->requestFactory, '');

        self::assertSame([], $provider->embed('some text'));
    }

    #[Test]
    public function embedReturnsVectorFromApiResponse(): void
    {
        $this->requestFactory
            ->method('request')
            ->with(
                self::stringContains('api.openai.com'),
                'POST',
                self::callback(static function (array $options): bool {
                    return ($options['headers']['Authorization'] ?? '') === 'Bearer sk-test';
                }),
            )
            ->willReturn($this->makeResponseMock(200, json_encode([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ], JSON_THROW_ON_ERROR)));

        $provider = new OpenAiEmbeddingProvider($this->requestFactory, 'sk-test');

        self::assertSame([0.1, 0.2, 0.3], $provider->embed('some text'));
    }

    #[Test]
    public function embedThrowsOnNon200Response(): void
    {
        $this->requestFactory->method('request')->willReturn($this->makeResponseMock(401, '{}'));

        $provider = new OpenAiEmbeddingProvider($this->requestFactory, 'sk-test');

        $this->expectException(\RuntimeException::class);
        $provider->embed('some text');
    }

    #[Test]
    public function getDimensionsReturnsConfiguredValue(): void
    {
        $provider = new OpenAiEmbeddingProvider($this->requestFactory, 'sk-test', dimensions: 3072);

        self::assertSame(3072, $provider->getDimensions());
    }

    #[Test]
    public function getMaxInputTokensReturns8191(): void
    {
        $provider = new OpenAiEmbeddingProvider($this->requestFactory, 'sk-test');

        self::assertSame(8191, $provider->getMaxInputTokens());
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Provider\Embedding;

use Maispace\MaiSearch\Provider\Embedding\CustomEmbeddingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class CustomEmbeddingProviderTest extends TestCase
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
    public function embedReturnsEmptyArrayWhenUrlIsEmpty(): void
    {
        $this->requestFactory->expects(self::never())->method('request');

        $provider = new CustomEmbeddingProvider($this->requestFactory, '');

        self::assertSame([], $provider->embed('text'));
    }

    #[Test]
    public function embedExtractsVectorByConfiguredResponsePath(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponseMock(200, json_encode([
                'result' => ['vector' => [0.7, 0.8]],
            ], JSON_THROW_ON_ERROR)));

        $provider = new CustomEmbeddingProvider(
            $this->requestFactory,
            'https://embed.example.com',
            responsePath: 'result.vector',
        );

        self::assertSame([0.7, 0.8], $provider->embed('text'));
    }

    #[Test]
    public function embedFillsInRequestTemplateWithText(): void
    {
        $this->requestFactory
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(array $options): bool => $options['body'] === '{"input": "hello world"}'),
            )
            ->willReturn($this->makeResponseMock(200, json_encode(['data' => [['embedding' => []]]], JSON_THROW_ON_ERROR)));

        $provider = new CustomEmbeddingProvider($this->requestFactory, 'https://embed.example.com');
        $provider->embed('hello world');
    }

    #[Test]
    public function embedThrowsOnNon200Response(): void
    {
        $this->requestFactory->method('request')->willReturn($this->makeResponseMock(503, '{}'));

        $provider = new CustomEmbeddingProvider($this->requestFactory, 'https://embed.example.com');

        $this->expectException(\RuntimeException::class);
        $provider->embed('text');
    }

    #[Test]
    public function embedReturnsEmptyArrayWhenResponsePathIsNotAnArray(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponseMock(200, json_encode(['result' => ['vector' => 'not-an-array']], JSON_THROW_ON_ERROR)));

        $provider = new CustomEmbeddingProvider(
            $this->requestFactory,
            'https://embed.example.com',
            responsePath: 'result.vector',
        );

        self::assertSame([], $provider->embed('text'));
    }

    #[Test]
    public function getMaxInputTokensReturnsConfiguredValue(): void
    {
        $provider = new CustomEmbeddingProvider($this->requestFactory, 'https://embed.example.com', maxInputTokens: 4096);

        self::assertSame(4096, $provider->getMaxInputTokens());
    }
}

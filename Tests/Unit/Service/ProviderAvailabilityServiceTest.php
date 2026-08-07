<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Service\ProviderAvailabilityService;
use Maispace\MaiSearch\Service\ProviderFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class ProviderAvailabilityServiceTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    private ProviderFactory&MockObject $providerFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->providerFactory = $this->createMock(ProviderFactory::class);
    }

    private function service(): ProviderAvailabilityService
    {
        return new ProviderAvailabilityService($this->requestFactory, $this->providerFactory);
    }

    #[Test]
    public function embeddingIsAvailableWhenOpenAiKeyIsConfigured(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('openai');
        $this->providerFactory->method('getOpenAiApiKey')->willReturn('sk-test');

        self::assertTrue($this->service()->isEmbeddingProviderAvailable());
    }

    #[Test]
    public function embeddingIsUnavailableWhenOpenAiKeyIsMissing(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('openai');
        $this->providerFactory->method('getOpenAiApiKey')->willReturn('');
        $this->requestFactory->expects(self::never())->method('request');

        self::assertFalse($this->service()->isEmbeddingProviderAvailable());
    }

    #[Test]
    public function chatIsAvailableWhenCustomUrlIsConfigured(): void
    {
        $this->providerFactory->method('getActiveChatProviderIdentifier')->willReturn('custom');
        $this->providerFactory->method('getCustomChatUrl')->willReturn('https://chat.example.com');
        $this->requestFactory->expects(self::never())->method('request');

        self::assertTrue($this->service()->isChatProviderAvailable());
    }

    #[Test]
    public function chatIsUnavailableWhenCustomUrlIsMissing(): void
    {
        $this->providerFactory->method('getActiveChatProviderIdentifier')->willReturn('custom');
        $this->providerFactory->method('getCustomChatUrl')->willReturn('');

        self::assertFalse($this->service()->isChatProviderAvailable());
    }

    #[Test]
    public function ollamaEmbeddingIsAvailableWhenTagsEndpointResponds(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('ollama');
        $this->providerFactory->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with('http://localhost:11434/api/tags', 'GET', self::callback(
                static fn(array $options): bool => $options['timeout'] === 2 && $options['http_errors'] === false,
            ))
            ->willReturn($response);

        self::assertTrue($this->service()->isEmbeddingProviderAvailable());
    }

    #[Test]
    public function ollamaChatIsUnavailableWhenTagsEndpointReturnsErrorStatus(): void
    {
        $this->providerFactory->method('getActiveChatProviderIdentifier')->willReturn('ollama');
        $this->providerFactory->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $this->requestFactory->method('request')->willReturn($response);

        self::assertFalse($this->service()->isChatProviderAvailable());
    }

    #[Test]
    public function ollamaIsUnavailableWhenRequestThrows(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('ollama');
        $this->providerFactory->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->requestFactory->method('request')->willThrowException(new \RuntimeException('connection refused'));

        self::assertFalse($this->service()->isEmbeddingProviderAvailable());
    }

    #[Test]
    public function unknownProviderIdentifierIsUnavailable(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('unknown');

        self::assertFalse($this->service()->isEmbeddingProviderAvailable());
    }

    #[Test]
    public function resultIsCachedForTheLifetimeOfTheInstance(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('openai');
        $this->providerFactory->expects(self::once())->method('getOpenAiApiKey')->willReturn('sk-test');

        $service = $this->service();

        self::assertTrue($service->isEmbeddingProviderAvailable());
        self::assertTrue($service->isEmbeddingProviderAvailable());
    }

    #[Test]
    public function embeddingAndChatAvailabilityAreTrackedIndependently(): void
    {
        $this->providerFactory->method('getActiveEmbeddingProviderIdentifier')->willReturn('openai');
        $this->providerFactory->method('getOpenAiApiKey')->willReturn('sk-test');
        $this->providerFactory->method('getActiveChatProviderIdentifier')->willReturn('custom');
        $this->providerFactory->method('getCustomChatUrl')->willReturn('');

        $service = $this->service();

        self::assertTrue($service->isEmbeddingProviderAvailable());
        self::assertFalse($service->isChatProviderAvailable());
    }
}

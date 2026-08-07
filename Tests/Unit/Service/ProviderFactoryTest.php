<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Provider\Chat\CustomChatProvider;
use Maispace\MaiSearch\Provider\Chat\OllamaChatProvider;
use Maispace\MaiSearch\Provider\Chat\OpenAiChatProvider;
use Maispace\MaiSearch\Provider\Embedding\CustomEmbeddingProvider;
use Maispace\MaiSearch\Provider\Embedding\OllamaEmbeddingProvider;
use Maispace\MaiSearch\Provider\Embedding\OpenAiEmbeddingProvider;
use Maispace\MaiSearch\Service\ProviderFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class ProviderFactoryTest extends TestCase
{
    private function createFactory(array $settings): ProviderFactory
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($settings);

        return new ProviderFactory($this->createMock(RequestFactory::class), $extensionConfiguration);
    }

    private function privateProperty(object $object, string $property): mixed
    {
        $reflectionProperty = new \ReflectionProperty($object, $property);

        return $reflectionProperty->getValue($object);
    }

    #[Test]
    public function createOpenAiEmbeddingProviderUsesConfiguredSettings(): void
    {
        $factory = $this->createFactory([
            'openai' => ['apiKey' => 'sk-test', 'embeddingModel' => 'text-embedding-3-large'],
        ]);

        $provider = $factory->createOpenAiEmbeddingProvider();

        self::assertInstanceOf(OpenAiEmbeddingProvider::class, $provider);
        self::assertSame('sk-test', $this->privateProperty($provider, 'apiKey'));
        self::assertSame('text-embedding-3-large', $this->privateProperty($provider, 'model'));
    }

    #[Test]
    public function createOpenAiEmbeddingProviderUsesDefaultModelWhenUnconfigured(): void
    {
        $provider = $this->createFactory([])->createOpenAiEmbeddingProvider();

        self::assertSame('', $this->privateProperty($provider, 'apiKey'));
        self::assertSame('text-embedding-3-small', $this->privateProperty($provider, 'model'));
    }

    #[Test]
    public function createOllamaEmbeddingProviderUsesConfiguredSettings(): void
    {
        $factory = $this->createFactory([
            'ollama' => ['baseUrl' => 'http://ollama.local:11434', 'embeddingModel' => 'custom-embed'],
        ]);

        $provider = $factory->createOllamaEmbeddingProvider();

        self::assertInstanceOf(OllamaEmbeddingProvider::class, $provider);
        self::assertSame('http://ollama.local:11434', $this->privateProperty($provider, 'baseUrl'));
        self::assertSame('custom-embed', $this->privateProperty($provider, 'model'));
    }

    #[Test]
    public function createCustomEmbeddingProviderUsesConfiguredSettings(): void
    {
        $factory = $this->createFactory([
            'custom' => ['embeddingUrl' => 'https://embed.example.com', 'apiKey' => 'custom-key'],
        ]);

        $provider = $factory->createCustomEmbeddingProvider();

        self::assertInstanceOf(CustomEmbeddingProvider::class, $provider);
        self::assertSame('https://embed.example.com', $this->privateProperty($provider, 'url'));
        self::assertSame('custom-key', $this->privateProperty($provider, 'apiKey'));
    }

    #[Test]
    public function createOpenAiChatProviderUsesConfiguredSettings(): void
    {
        $factory = $this->createFactory([
            'openai' => ['apiKey' => 'sk-chat', 'chatModel' => 'gpt-4o'],
        ]);

        $provider = $factory->createOpenAiChatProvider();

        self::assertInstanceOf(OpenAiChatProvider::class, $provider);
        self::assertSame('sk-chat', $this->privateProperty($provider, 'apiKey'));
        self::assertSame('gpt-4o', $this->privateProperty($provider, 'model'));
    }

    #[Test]
    public function createOllamaChatProviderUsesDefaultsWhenUnconfigured(): void
    {
        $provider = $this->createFactory([])->createOllamaChatProvider();

        self::assertInstanceOf(OllamaChatProvider::class, $provider);
        self::assertSame('http://localhost:11434', $this->privateProperty($provider, 'baseUrl'));
        self::assertSame('llama3.1', $this->privateProperty($provider, 'model'));
    }

    #[Test]
    public function createCustomChatProviderUsesConfiguredSettings(): void
    {
        $factory = $this->createFactory([
            'custom' => ['chatUrl' => 'https://chat.example.com', 'apiKey' => 'chat-key'],
        ]);

        $provider = $factory->createCustomChatProvider();

        self::assertInstanceOf(CustomChatProvider::class, $provider);
        self::assertSame('https://chat.example.com', $this->privateProperty($provider, 'url'));
    }

    #[Test]
    public function getActiveEmbeddingProviderIdentifierDefaultsToOpenai(): void
    {
        self::assertSame('openai', $this->createFactory([])->getActiveEmbeddingProviderIdentifier());
    }

    #[Test]
    public function getActiveEmbeddingProviderIdentifierReturnsConfiguredValue(): void
    {
        $factory = $this->createFactory(['embeddingProvider' => 'ollama']);

        self::assertSame('ollama', $factory->getActiveEmbeddingProviderIdentifier());
    }

    #[Test]
    public function getActiveChatProviderIdentifierDefaultsToOpenai(): void
    {
        self::assertSame('openai', $this->createFactory([])->getActiveChatProviderIdentifier());
    }

    #[Test]
    public function getActiveChatProviderIdentifierReturnsConfiguredValue(): void
    {
        $factory = $this->createFactory(['chatProvider' => 'custom']);

        self::assertSame('custom', $factory->getActiveChatProviderIdentifier());
    }

    #[Test]
    public function getOpenAiApiKeyReturnsConfiguredValue(): void
    {
        $factory = $this->createFactory(['openai' => ['apiKey' => 'sk-test']]);

        self::assertSame('sk-test', $factory->getOpenAiApiKey());
    }

    #[Test]
    public function getOpenAiApiKeyDefaultsToEmptyString(): void
    {
        self::assertSame('', $this->createFactory([])->getOpenAiApiKey());
    }

    #[Test]
    public function getOllamaBaseUrlDefaultsToLocalhost(): void
    {
        self::assertSame('http://localhost:11434', $this->createFactory([])->getOllamaBaseUrl());
    }

    #[Test]
    public function getOllamaBaseUrlReturnsConfiguredValue(): void
    {
        $factory = $this->createFactory(['ollama' => ['baseUrl' => 'http://ollama.local:11434']]);

        self::assertSame('http://ollama.local:11434', $factory->getOllamaBaseUrl());
    }

    #[Test]
    public function getCustomEmbeddingUrlDefaultsToEmptyString(): void
    {
        self::assertSame('', $this->createFactory([])->getCustomEmbeddingUrl());
    }

    #[Test]
    public function getCustomEmbeddingUrlReturnsConfiguredValue(): void
    {
        $factory = $this->createFactory(['custom' => ['embeddingUrl' => 'https://embed.example.com']]);

        self::assertSame('https://embed.example.com', $factory->getCustomEmbeddingUrl());
    }

    #[Test]
    public function getCustomChatUrlDefaultsToEmptyString(): void
    {
        self::assertSame('', $this->createFactory([])->getCustomChatUrl());
    }

    #[Test]
    public function getCustomChatUrlReturnsConfiguredValue(): void
    {
        $factory = $this->createFactory(['custom' => ['chatUrl' => 'https://chat.example.com']]);

        self::assertSame('https://chat.example.com', $factory->getCustomChatUrl());
    }

    #[Test]
    public function fallsBackToEmptySettingsWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not configured'));

        $factory = new ProviderFactory($this->createMock(RequestFactory::class), $extensionConfiguration);

        self::assertSame('openai', $factory->getActiveEmbeddingProviderIdentifier());
    }
}

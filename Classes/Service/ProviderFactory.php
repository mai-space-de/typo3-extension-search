<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Provider\Chat\CustomChatProvider;
use Maispace\MaiSearch\Provider\Chat\OllamaChatProvider;
use Maispace\MaiSearch\Provider\Chat\OpenAiChatProvider;
use Maispace\MaiSearch\Provider\Embedding\CustomEmbeddingProvider;
use Maispace\MaiSearch\Provider\Embedding\OllamaEmbeddingProvider;
use Maispace\MaiSearch\Provider\Embedding\OpenAiEmbeddingProvider;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds each concrete provider from Extension Configuration. Referenced as a
 * Symfony service factory from Configuration/Services.yaml, so provider
 * constructor arguments (API keys, base URLs, models) never need to be
 * autowired — only this one class reads ExtensionConfiguration.
 */
class ProviderFactory
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $settings;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        ?ExtensionConfiguration $extensionConfiguration = null,
    ) {
        $extensionConfiguration ??= GeneralUtility::makeInstance(ExtensionConfiguration::class);

        try {
            $this->settings = $extensionConfiguration->get('mai_search');
        } catch (\Exception) {
            $this->settings = [];
        }
    }

    public function createOpenAiEmbeddingProvider(): OpenAiEmbeddingProvider
    {
        return new OpenAiEmbeddingProvider(
            $this->requestFactory,
            (string) ($this->settings['openai']['apiKey'] ?? ''),
            (string) ($this->settings['openai']['embeddingModel'] ?? 'text-embedding-3-small'),
            (int) ($this->settings['openai']['dimensions'] ?? 1536),
        );
    }

    public function createOllamaEmbeddingProvider(): OllamaEmbeddingProvider
    {
        return new OllamaEmbeddingProvider(
            $this->requestFactory,
            (string) ($this->settings['ollama']['baseUrl'] ?? 'http://localhost:11434'),
            (string) ($this->settings['ollama']['embeddingModel'] ?? 'nomic-embed-text'),
            (int) ($this->settings['ollama']['dimensions'] ?? 768),
        );
    }

    public function createCustomEmbeddingProvider(): CustomEmbeddingProvider
    {
        return new CustomEmbeddingProvider(
            $this->requestFactory,
            (string) ($this->settings['custom']['embeddingUrl'] ?? ''),
            (string) ($this->settings['custom']['apiKey'] ?? ''),
            dimensions: (int) ($this->settings['custom']['dimensions'] ?? 0),
        );
    }

    public function createOpenAiChatProvider(): OpenAiChatProvider
    {
        return new OpenAiChatProvider(
            $this->requestFactory,
            (string) ($this->settings['openai']['apiKey'] ?? ''),
            (string) ($this->settings['openai']['chatModel'] ?? 'gpt-4o-mini'),
        );
    }

    public function createOllamaChatProvider(): OllamaChatProvider
    {
        return new OllamaChatProvider(
            $this->requestFactory,
            (string) ($this->settings['ollama']['baseUrl'] ?? 'http://localhost:11434'),
            (string) ($this->settings['ollama']['chatModel'] ?? 'llama3.1'),
        );
    }

    public function createCustomChatProvider(): CustomChatProvider
    {
        return new CustomChatProvider(
            $this->requestFactory,
            (string) ($this->settings['custom']['chatUrl'] ?? ''),
            (string) ($this->settings['custom']['apiKey'] ?? ''),
        );
    }

    public function getActiveEmbeddingProviderIdentifier(): string
    {
        return (string) ($this->settings['embeddingProvider'] ?? 'openai');
    }

    public function getActiveChatProviderIdentifier(): string
    {
        return (string) ($this->settings['chatProvider'] ?? 'openai');
    }

    public function getOpenAiApiKey(): string
    {
        return (string) ($this->settings['openai']['apiKey'] ?? '');
    }

    public function getOllamaBaseUrl(): string
    {
        return (string) ($this->settings['ollama']['baseUrl'] ?? 'http://localhost:11434');
    }

    public function getCustomEmbeddingUrl(): string
    {
        return (string) ($this->settings['custom']['embeddingUrl'] ?? '');
    }

    public function getCustomChatUrl(): string
    {
        return (string) ($this->settings['custom']['chatUrl'] ?? '');
    }
}

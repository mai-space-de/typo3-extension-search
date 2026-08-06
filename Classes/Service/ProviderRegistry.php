<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Domain\Service\ChatProviderInterface;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Collects all DI-tagged embedding/chat providers (via ProviderRegistryCompilerPass)
 * and resolves the active one from Extension Configuration.
 */
class ProviderRegistry implements SingletonInterface
{
    /**
     * @var array<string, EmbeddingProviderInterface>
     */
    private array $embeddingProviders = [];

    /**
     * @var array<string, ChatProviderInterface>
     */
    private array $chatProviders = [];

    public function __construct(private readonly ProviderFactory $providerFactory) {}

    public function addEmbeddingProvider(EmbeddingProviderInterface $provider): void
    {
        $this->embeddingProviders[$provider->getIdentifier()] = $provider;
    }

    public function addChatProvider(ChatProviderInterface $provider): void
    {
        $this->chatProviders[$provider->getIdentifier()] = $provider;
    }

    public function getEmbeddingProvider(string $identifier): EmbeddingProviderInterface
    {
        return $this->embeddingProviders[$identifier]
            ?? throw new \OutOfBoundsException(sprintf('No embedding provider registered for "%s"', $identifier));
    }

    public function getChatProvider(string $identifier): ChatProviderInterface
    {
        return $this->chatProviders[$identifier]
            ?? throw new \OutOfBoundsException(sprintf('No chat provider registered for "%s"', $identifier));
    }

    public function getActiveEmbeddingProvider(): EmbeddingProviderInterface
    {
        return $this->getEmbeddingProvider($this->providerFactory->getActiveEmbeddingProviderIdentifier());
    }

    public function getActiveChatProvider(): ChatProviderInterface
    {
        return $this->getChatProvider($this->providerFactory->getActiveChatProviderIdentifier());
    }
}

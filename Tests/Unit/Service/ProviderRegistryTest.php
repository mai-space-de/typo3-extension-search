<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Domain\Service\ChatProviderInterface;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Service\ProviderFactory;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProviderRegistryTest extends TestCase
{
    private function createEmbeddingProvider(string $identifier): EmbeddingProviderInterface
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('getIdentifier')->willReturn($identifier);

        return $provider;
    }

    private function createChatProvider(string $identifier): ChatProviderInterface
    {
        $provider = $this->createMock(ChatProviderInterface::class);
        $provider->method('getIdentifier')->willReturn($identifier);

        return $provider;
    }

    #[Test]
    public function getEmbeddingProviderReturnsRegisteredProvider(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $registry = new ProviderRegistry($factory);
        $provider = $this->createEmbeddingProvider('openai');

        $registry->addEmbeddingProvider($provider);

        self::assertSame($provider, $registry->getEmbeddingProvider('openai'));
    }

    #[Test]
    public function getEmbeddingProviderThrowsForUnknownIdentifier(): void
    {
        $registry = new ProviderRegistry($this->createMock(ProviderFactory::class));

        $this->expectException(\OutOfBoundsException::class);

        $registry->getEmbeddingProvider('unknown');
    }

    #[Test]
    public function getChatProviderReturnsRegisteredProvider(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $registry = new ProviderRegistry($factory);
        $provider = $this->createChatProvider('ollama');

        $registry->addChatProvider($provider);

        self::assertSame($provider, $registry->getChatProvider('ollama'));
    }

    #[Test]
    public function getChatProviderThrowsForUnknownIdentifier(): void
    {
        $registry = new ProviderRegistry($this->createMock(ProviderFactory::class));

        $this->expectException(\OutOfBoundsException::class);

        $registry->getChatProvider('unknown');
    }

    #[Test]
    public function getActiveEmbeddingProviderResolvesViaProviderFactory(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $factory->method('getActiveEmbeddingProviderIdentifier')->willReturn('ollama');

        $registry = new ProviderRegistry($factory);
        $ollama = $this->createEmbeddingProvider('ollama');
        $openai = $this->createEmbeddingProvider('openai');
        $registry->addEmbeddingProvider($openai);
        $registry->addEmbeddingProvider($ollama);

        self::assertSame($ollama, $registry->getActiveEmbeddingProvider());
    }

    #[Test]
    public function getActiveChatProviderResolvesViaProviderFactory(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $factory->method('getActiveChatProviderIdentifier')->willReturn('custom');

        $registry = new ProviderRegistry($factory);
        $custom = $this->createChatProvider('custom');
        $openai = $this->createChatProvider('openai');
        $registry->addChatProvider($openai);
        $registry->addChatProvider($custom);

        self::assertSame($custom, $registry->getActiveChatProvider());
    }
}

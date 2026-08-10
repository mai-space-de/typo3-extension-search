<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Maispace\MaiSearch\Service\QueryEmbeddingCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class QueryEmbeddingCacheServiceTest extends TestCase
{
    private FrontendInterface&MockObject $cache;
    private ProviderRegistry&MockObject $providerRegistry;
    private EmbeddingProviderInterface&MockObject $embeddingProvider;
    private RagConfiguration&MockObject $ragConfiguration;
    private QueryEmbeddingCacheService $subject;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(FrontendInterface::class);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_query_embedding')->willReturn($this->cache);

        $this->embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $this->embeddingProvider->method('getIdentifier')->willReturn('openai');

        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($this->embeddingProvider);

        $this->ragConfiguration = $this->createMock(RagConfiguration::class);
        $this->ragConfiguration->method('getQueryEmbeddingCacheTtl')->willReturn(86400);

        $this->subject = new QueryEmbeddingCacheService($this->providerRegistry, $cacheManager, $this->ragConfiguration);
    }

    #[Test]
    public function embedReturnsCachedVectorOnHitWithoutCallingProvider(): void
    {
        $this->cache->method('get')->willReturn([0.1, 0.2, 0.3]);
        $this->embeddingProvider->expects(self::never())->method('embed');

        self::assertSame([0.1, 0.2, 0.3], $this->subject->embed('was ist typo3?'));
    }

    #[Test]
    public function embedCallsProviderAndStoresResultOnCacheMiss(): void
    {
        $this->cache->method('get')->willReturn(false);
        $this->embeddingProvider->expects(self::once())->method('embed')->with('was ist typo3?')->willReturn([0.4, 0.5]);

        $this->cache
            ->expects(self::once())
            ->method('set')
            ->with(self::isType('string'), [0.4, 0.5], [], 86400);

        self::assertSame([0.4, 0.5], $this->subject->embed('was ist typo3?'));
    }

    #[Test]
    public function embedDoesNotCacheAnEmptyVector(): void
    {
        $this->cache->method('get')->willReturn(false);
        $this->embeddingProvider->method('embed')->willReturn([]);

        $this->cache->expects(self::never())->method('set');

        self::assertSame([], $this->subject->embed('broken provider response'));
    }

    #[Test]
    public function cacheKeyIsCaseInsensitiveAndTrimmedOnQuery(): void
    {
        $keys = [];
        $this->cache->method('get')->willReturnCallback(function (string $key) use (&$keys): bool {
            $keys[] = $key;

            return false;
        });
        $this->embeddingProvider->method('embed')->willReturn([0.1]);

        $this->subject->embed('Was ist TYPO3?');
        $this->subject->embed('  was ist typo3?  ');

        self::assertCount(2, $keys);
        self::assertSame($keys[0], $keys[1]);
    }

    #[Test]
    public function cacheKeyIsIsolatedByActiveProviderIdentifier(): void
    {
        $keys = [];
        $this->cache->method('get')->willReturnCallback(function (string $key) use (&$keys): bool {
            $keys[] = $key;

            return false;
        });
        $this->embeddingProvider->method('embed')->willReturn([0.1]);

        $this->subject->embed('same query');

        $ollamaProvider = $this->createMock(EmbeddingProviderInterface::class);
        $ollamaProvider->method('getIdentifier')->willReturn('ollama');
        $ollamaProvider->method('embed')->willReturn([0.1]);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($ollamaProvider);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_query_embedding')->willReturn($this->cache);
        $subjectWithOllama = new QueryEmbeddingCacheService($this->providerRegistry, $cacheManager, $this->ragConfiguration);

        $subjectWithOllama->embed('same query');

        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    #[Test]
    public function embedPropagatesProviderExceptions(): void
    {
        $this->cache->method('get')->willReturn(false);
        $this->embeddingProvider->method('embed')->willThrowException(new \RuntimeException('provider down'));

        $this->expectException(\RuntimeException::class);

        $this->subject->embed('query');
    }
}

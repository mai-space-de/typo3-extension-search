<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Service\AnswerCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class AnswerCacheServiceTest extends TestCase
{
    private FrontendInterface&MockObject $cache;
    private AnswerCacheService $subject;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(FrontendInterface::class);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_answer')->willReturn($this->cache);
        $this->subject = new AnswerCacheService($cacheManager);
    }

    #[Test]
    public function getReturnsNullOnCacheMiss(): void
    {
        $this->cache->method('get')->willReturn(false);

        self::assertNull($this->subject->get('query', ['/a'], []));
    }

    #[Test]
    public function getReturnsCachedAnswerOnHit(): void
    {
        $this->cache->method('get')->willReturn('cached answer');

        self::assertSame('cached answer', $this->subject->get('query', ['/a'], []));
    }

    #[Test]
    public function getAndSetUseTheSameCacheKeyForIdenticalInput(): void
    {
        $capturedKey = null;
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$capturedKey): void {
            $capturedKey = $key;
        });
        $this->subject->set('What is TYPO3?', ['/a', '/b'], [], 'answer');

        $this->cache->expects(self::once())
            ->method('get')
            ->with(self::callback(function (string $key) use (&$capturedKey): bool {
                return $key === $capturedKey;
            }))
            ->willReturn(false);

        $this->subject->get('What is TYPO3?', ['/a', '/b'], []);
    }

    #[Test]
    public function differentPromptFingerprintsProduceDifferentCacheKeys(): void
    {
        $keys = [];
        $this->cache->method('get')->willReturnCallback(function (string $key) use (&$keys): bool {
            $keys[] = $key;

            return false;
        });

        $this->subject->get('query', ['/a'], [], 'fingerprint-a');
        $this->subject->get('query', ['/a'], [], 'fingerprint-b');

        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    #[Test]
    public function getCacheKeyIsCaseInsensitiveOnQuery(): void
    {
        $keys = [];
        $this->cache->method('get')->willReturnCallback(function (string $key) use (&$keys): bool {
            $keys[] = $key;

            return false;
        });

        $this->subject->get('What Is TYPO3?', ['/a'], []);
        $this->subject->get('what is typo3?', ['/a'], []);

        self::assertCount(2, $keys);
        self::assertSame($keys[0], $keys[1]);
    }

    #[Test]
    public function setTagsCacheEntryWithGlobalTagAndOnePerContextUrl(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isType('string'),
                'the answer',
                ['maisearch_answer', 'context_' . md5('/news/1'), 'context_' . md5('/news/2')],
                3600,
            );

        $this->subject->set('query', ['/news/1', '/news/2'], [], 'the answer');
    }

    #[Test]
    public function setAcceptsACustomTtl(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('set')
            ->with(self::isType('string'), 'answer', self::anything(), 60);

        $this->subject->set('query', [], [], 'answer', 60);
    }

    #[Test]
    public function invalidateForUrlFlushesTheSameTagSetTagsWith(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('flushByTag')
            ->with('context_' . md5('/news/1'));

        $this->subject->invalidateForUrl('/news/1');
    }

    #[Test]
    public function invalidateAllFlushesTheGlobalTag(): void
    {
        $this->cache->expects(self::once())->method('flushByTag')->with('maisearch_answer');

        $this->subject->invalidateAll();
    }
}

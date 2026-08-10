<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Configuration;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class RagConfigurationTest extends TestCase
{
    private function createConfiguration(array $settings): RagConfiguration
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($settings);

        return new RagConfiguration($extensionConfiguration);
    }

    #[Test]
    public function defaultsWhenNothingConfigured(): void
    {
        $configuration = $this->createConfiguration([]);

        self::assertFalse($configuration->isRerankEnabled());
        self::assertSame(20, $configuration->getRerankWidth());
        self::assertSame(5, $configuration->getTopN());
        self::assertFalse($configuration->isStreamingEnabled());
        self::assertSame(6, $configuration->getHistoryMaxTurns());
        self::assertSame(86400, $configuration->getQueryEmbeddingCacheTtl());
        self::assertSame(3600, $configuration->getAnswerCacheTtl());
        self::assertSame(500, $configuration->getChunkSize());
        self::assertSame(50, $configuration->getChunkOverlap());
    }

    #[Test]
    public function readsConfiguredValues(): void
    {
        $configuration = $this->createConfiguration([
            'ragRerankEnabled' => '1',
            'ragRerankWidth' => '30',
            'ragTopN' => '8',
            'ragStreamingEnabled' => '1',
            'ragHistoryMaxTurns' => '10',
            'queryEmbeddingCacheTtl' => '43200',
            'ragAnswerCacheTtl' => '1800',
            'chunkSize' => '800',
            'chunkOverlap' => '100',
        ]);

        self::assertTrue($configuration->isRerankEnabled());
        self::assertSame(30, $configuration->getRerankWidth());
        self::assertSame(8, $configuration->getTopN());
        self::assertTrue($configuration->isStreamingEnabled());
        self::assertSame(10, $configuration->getHistoryMaxTurns());
        self::assertSame(43200, $configuration->getQueryEmbeddingCacheTtl());
        self::assertSame(1800, $configuration->getAnswerCacheTtl());
        self::assertSame(800, $configuration->getChunkSize());
        self::assertSame(100, $configuration->getChunkOverlap());
    }

    #[Test]
    public function cacheTtlsClampToZeroNotNegative(): void
    {
        $configuration = $this->createConfiguration([
            'queryEmbeddingCacheTtl' => '-1',
            'ragAnswerCacheTtl' => '-1',
        ]);

        self::assertSame(0, $configuration->getQueryEmbeddingCacheTtl());
        self::assertSame(0, $configuration->getAnswerCacheTtl());
    }

    #[Test]
    public function chunkSizeClampsToAtLeastOne(): void
    {
        $configuration = $this->createConfiguration(['chunkSize' => '0']);

        self::assertSame(1, $configuration->getChunkSize());
    }

    #[Test]
    public function chunkOverlapNeverReachesOrExceedsChunkSize(): void
    {
        $configuration = $this->createConfiguration(['chunkSize' => '100', 'chunkOverlap' => '500']);

        self::assertSame(99, $configuration->getChunkOverlap());
    }

    #[Test]
    public function rerankWidthAndTopNAreClampedToAtLeastOne(): void
    {
        $configuration = $this->createConfiguration([
            'ragRerankWidth' => '0',
            'ragTopN' => '-5',
        ]);

        self::assertSame(1, $configuration->getRerankWidth());
        self::assertSame(1, $configuration->getTopN());
    }

    #[Test]
    public function historyMaxTurnsClampsToZeroNotNegative(): void
    {
        $configuration = $this->createConfiguration(['ragHistoryMaxTurns' => '-3']);

        self::assertSame(0, $configuration->getHistoryMaxTurns());
    }

    #[Test]
    public function fallsBackToDefaultsWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not found'));

        $configuration = new RagConfiguration($extensionConfiguration);

        self::assertFalse($configuration->isRerankEnabled());
        self::assertSame(20, $configuration->getRerankWidth());
    }
}

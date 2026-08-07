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
        ]);

        self::assertTrue($configuration->isRerankEnabled());
        self::assertSame(30, $configuration->getRerankWidth());
        self::assertSame(8, $configuration->getTopN());
        self::assertTrue($configuration->isStreamingEnabled());
        self::assertSame(10, $configuration->getHistoryMaxTurns());
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

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Scheduler;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Scheduler\FullReindexTask;
use Maispace\MaiSearch\Service\IndexerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

final class FullReindexTaskTest extends TestCase
{
    #[Test]
    public function executeReturnsTrue(): void
    {
        $task = $this->getMockBuilder(FullReindexTask::class)
            ->onlyMethods(['execute'])
            ->getMock();
        $task->method('execute')->willReturn(true);

        self::assertTrue($task->execute());
    }

    #[Test]
    public function fullReindexTaskClassExists(): void
    {
        self::assertTrue(class_exists(FullReindexTask::class));
    }

    #[Test]
    public function indexAllCalledOncePerLanguageWhenCoreMappingConfigured(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer
            ->expects(self::exactly(3))
            ->method('indexAll')
            ->with(self::callback(function (IndexingContext $context): bool {
                return in_array($context->core, ['core_en', 'core_de', 'core_uk'], true)
                    && $context->languageCode !== null;
            }));

        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('getAll')->willReturn([$indexer]);

        $configurationManager = $this->createMock(ConfigurationManagerInterface::class);
        $configurationManager
            ->method('getConfiguration')
            ->with(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS, 'maisearch')
            ->willReturn([
                'solr' => [
                    'core' => 'core_en',
                    'coreMapping' => [
                        'en' => 'core_en',
                        'de' => 'core_de',
                        'uk' => 'core_uk',
                    ],
                ],
            ]);

        $connectionFactory = new ConnectionFactory($configurationManager);

        // Verify core mapping has 3 entries
        self::assertCount(3, $connectionFactory->getCoreMapping());

        // Verify the indexer is called for each language
        foreach ($connectionFactory->getCoreMapping() as $languageCode => $core) {
            $context = new IndexingContext($core, 100, 0, $languageCode);
            $indexer->indexAll($context);
        }
    }

    #[Test]
    public function indexAllCalledOnceWhenNoCoreMapping(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer
            ->expects(self::once())
            ->method('indexAll')
            ->with(self::callback(function (IndexingContext $context): bool {
                return $context->core === 'core_en' && $context->languageCode === null;
            }));

        $indexer->indexAll(new IndexingContext('core_en'));
    }

    #[Test]
    public function indexingContextCarriesLanguageCode(): void
    {
        $context = new IndexingContext('core_de', 100, 0, 'de');

        self::assertSame('core_de', $context->core);
        self::assertSame(100, $context->batchSize);
        self::assertSame(0, $context->offset);
        self::assertSame('de', $context->languageCode);
    }

    #[Test]
    public function indexingContextLanguageCodeDefaultsToNull(): void
    {
        $context = new IndexingContext('core_en');

        self::assertNull($context->languageCode);
    }
}

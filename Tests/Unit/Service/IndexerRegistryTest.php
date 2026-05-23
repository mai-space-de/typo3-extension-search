<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Service\IndexerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IndexerRegistryTest extends TestCase
{
    private function createMockIndexer(string $type, string $table = ''): SearchIndexerInterface
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->method('getType')->willReturn($type);
        $indexer->method('supports')->willReturnCallback(
            static fn(string $t) => $t === $table,
        );

        return $indexer;
    }

    #[Test]
    public function addIndexerAndGetByType(): void
    {
        $registry = new IndexerRegistry();
        $indexer = $this->createMockIndexer('news', 'tt_news');

        $registry->addIndexer($indexer);

        self::assertSame($indexer, $registry->getIndexer('news'));
    }

    #[Test]
    public function getIndexerReturnsNullForUnknownType(): void
    {
        $registry = new IndexerRegistry();

        self::assertNull($registry->getIndexer('unknown'));
    }

    #[Test]
    public function getAllReturnsAllRegisteredIndexers(): void
    {
        $registry = new IndexerRegistry();
        $newsIndexer = $this->createMockIndexer('news', 'tt_news');
        $pageIndexer = $this->createMockIndexer('page', 'pages');

        $registry->addIndexer($newsIndexer);
        $registry->addIndexer($pageIndexer);

        $all = $registry->getAll();

        self::assertCount(2, $all);
        self::assertContains($newsIndexer, $all);
        self::assertContains($pageIndexer, $all);
    }

    #[Test]
    public function getIndexerForTableReturnsMatchingIndexer(): void
    {
        $registry = new IndexerRegistry();
        $newsIndexer = $this->createMockIndexer('news', 'tt_news');
        $pageIndexer = $this->createMockIndexer('page', 'pages');

        $registry->addIndexer($newsIndexer);
        $registry->addIndexer($pageIndexer);

        self::assertSame($newsIndexer, $registry->getIndexerForTable('tt_news'));
        self::assertSame($pageIndexer, $registry->getIndexerForTable('pages'));
    }

    #[Test]
    public function getIndexerForTableReturnsNullForUnsupportedTable(): void
    {
        $registry = new IndexerRegistry();
        $indexer = $this->createMockIndexer('news', 'tt_news');

        $registry->addIndexer($indexer);

        self::assertNull($registry->getIndexerForTable('unknown_table'));
    }
}

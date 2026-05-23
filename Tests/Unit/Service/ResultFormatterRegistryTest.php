<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResultFormatterRegistryTest extends TestCase
{
    private function createMockFormatter(string $type): SearchResultFormatterInterface
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
        $formatter->method('getType')->willReturn($type);

        return $formatter;
    }

    #[Test]
    public function addFormatterAndGetByType(): void
    {
        $registry = new ResultFormatterRegistry();
        $formatter = $this->createMockFormatter('news');

        $registry->addFormatter($formatter);

        self::assertSame($formatter, $registry->getFormatter('news'));
    }

    #[Test]
    public function getFormatterReturnsNullForUnknownType(): void
    {
        $registry = new ResultFormatterRegistry();

        self::assertNull($registry->getFormatter('unknown'));
    }

    #[Test]
    public function getAllReturnsAllRegisteredFormatters(): void
    {
        $registry = new ResultFormatterRegistry();
        $newsFormatter = $this->createMockFormatter('news');
        $pageFormatter = $this->createMockFormatter('page');

        $registry->addFormatter($newsFormatter);
        $registry->addFormatter($pageFormatter);

        $all = $registry->getAll();

        self::assertCount(2, $all);
        self::assertContains($newsFormatter, $all);
        self::assertContains($pageFormatter, $all);
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Report;

use Maispace\MaiSearch\Report\IndexedSearchStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;

final class IndexedSearchStatusTest extends TestCase
{
    #[Test]
    public function getLabelReturnsIndexedSearch(): void
    {
        self::assertSame('Indexed Search', (new IndexedSearchStatus())->getLabel());
    }

    #[Test]
    public function getStatusReturnsOneInfoStatus(): void
    {
        $statuses = (new IndexedSearchStatus())->getStatus();

        self::assertCount(1, $statuses);
        self::assertInstanceOf(Status::class, $statuses[0]);
        self::assertSame(ContextualFeedbackSeverity::INFO, $statuses[0]->getSeverity());
    }
}

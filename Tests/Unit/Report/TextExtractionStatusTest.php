<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Report;

use Maispace\MaiSearch\Extraction\TikaTextExtractor;
use Maispace\MaiSearch\Report\TextExtractionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class TextExtractionStatusTest extends TestCase
{
    #[Test]
    public function getLabelReturnsTextExtraction(): void
    {
        self::assertSame('Text Extraction', (new TextExtractionStatus($this->createMock(TikaTextExtractor::class)))->getLabel());
    }

    #[Test]
    public function getStatusReportsOkWhenTikaIsAvailable(): void
    {
        $tika = $this->createMock(TikaTextExtractor::class);
        $tika->method('isAvailable')->willReturn(true);

        $statuses = (new TextExtractionStatus($tika))->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::OK, $statuses[0]->getSeverity());
    }

    #[Test]
    public function getStatusReportsWarningWhenTikaIsUnavailable(): void
    {
        $tika = $this->createMock(TikaTextExtractor::class);
        $tika->method('isAvailable')->willReturn(false);

        $statuses = (new TextExtractionStatus($tika))->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $statuses[0]->getSeverity());
    }
}

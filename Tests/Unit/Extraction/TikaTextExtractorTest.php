<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Extraction;

use Maispace\MaiSearch\Extraction\TikaTextExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * apache-solr-for-typo3/tika is not a dependency of this extension (only
 * class_exists()-guarded), so in this test environment — same as real CI —
 * TikaTextExtractor never finds its underlying class and consistently
 * degrades to "not available", which is exactly what these tests assert.
 */
final class TikaTextExtractorTest extends TestCase
{
    #[Test]
    public function isAvailableIsFalseWhenTikaClassDoesNotExist(): void
    {
        self::assertFalse((new TikaTextExtractor())->isAvailable());
    }

    #[Test]
    public function supportsIsFalseEvenForASupportedExtensionWhenTikaIsUnavailable(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('pdf');

        self::assertFalse((new TikaTextExtractor())->supports($file));
    }

    #[Test]
    public function supportsIsFalseForAnUnsupportedExtension(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('exe');

        self::assertFalse((new TikaTextExtractor())->supports($file));
    }

    #[Test]
    public function extractTextReturnsEmptyStringWhenUnsupported(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('exe');

        self::assertSame('', (new TikaTextExtractor())->extractText($file));
    }

    #[Test]
    public function getPriorityIsOneHundred(): void
    {
        self::assertSame(100, (new TikaTextExtractor())->getPriority());
    }
}

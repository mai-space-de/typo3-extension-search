<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Extraction;

use Maispace\MaiSearch\Extraction\ExtractorRegistry;
use Maispace\MaiSearch\Extraction\NullTextExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileInterface;

final class ExtractorRegistryTest extends TestCase
{
    #[Test]
    public function getExtractorForFileFallsBackToNullTextExtractorWhenTikaIsNotInstalled(): void
    {
        // apache-solr-for-typo3/tika is not a dependency of this extension, so
        // TikaTextExtractor::isAvailable() is always false in this test environment —
        // matching real CI, where that optional package is never installed either.
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('pdf');

        $extractor = (new ExtractorRegistry())->getExtractorForFile($file);

        self::assertInstanceOf(NullTextExtractor::class, $extractor);
    }

    #[Test]
    public function getExtractorForFileReturnsAnExtractorThatSupportsTheFile(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('txt');

        $extractor = (new ExtractorRegistry())->getExtractorForFile($file);

        self::assertTrue($extractor->supports($file));
    }
}

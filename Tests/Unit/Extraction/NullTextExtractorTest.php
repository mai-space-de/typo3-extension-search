<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Extraction;

use Maispace\MaiSearch\Extraction\NullTextExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileInterface;

final class NullTextExtractorTest extends TestCase
{
    #[Test]
    public function extractTextCombinesTitleAndDescription(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('hasProperty')->willReturnMap([['title', true], ['description', true]]);
        $file->method('getProperty')->willReturnMap([['title', 'A Title'], ['description', 'A description']]);

        self::assertSame("A Title\nA description", (new NullTextExtractor())->extractText($file));
    }

    #[Test]
    public function extractTextReturnsEmptyStringWhenNoPropertiesPresent(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('hasProperty')->willReturn(false);

        self::assertSame('', (new NullTextExtractor())->extractText($file));
    }

    #[Test]
    public function supportsAlwaysReturnsTrue(): void
    {
        self::assertTrue((new NullTextExtractor())->supports($this->createMock(FileInterface::class)));
    }

    #[Test]
    public function isAvailableAlwaysReturnsTrue(): void
    {
        self::assertTrue((new NullTextExtractor())->isAvailable());
    }

    #[Test]
    public function getPriorityIsZero(): void
    {
        self::assertSame(0, (new NullTextExtractor())->getPriority());
    }
}

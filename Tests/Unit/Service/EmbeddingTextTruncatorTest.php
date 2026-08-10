<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Service\EmbeddingTextTruncator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmbeddingTextTruncatorTest extends TestCase
{
    private EmbeddingTextTruncator $subject;

    protected function setUp(): void
    {
        $this->subject = new EmbeddingTextTruncator();
    }

    #[Test]
    public function returnsTextUnchangedWhenWithinLimit(): void
    {
        $text = str_repeat('a', 100);

        self::assertSame($text, $this->subject->truncate($text, 1000));
    }

    #[Test]
    public function truncatesTextExceedingTheEstimatedTokenLimit(): void
    {
        // 0.25 tokens/char, so 1000 chars ≈ 250 tokens.
        $text = str_repeat('a', 1000);

        $truncated = $this->subject->truncate($text, 100);

        self::assertLessThan(mb_strlen($text), mb_strlen($truncated));
        self::assertSame(400, mb_strlen($truncated));
    }

    #[Test]
    public function returnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->subject->truncate('', 100));
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Support;

use Maispace\MaiSearch\Support\ExtractsResponsePath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtractsResponsePathTest extends TestCase
{
    private function extractor(): object
    {
        return new class {
            use ExtractsResponsePath;

            public function extract(mixed $data, string $path): mixed
            {
                return $this->extractByPath($data, $path);
            }
        };
    }

    #[Test]
    public function extractsNestedValueByDotPath(): void
    {
        $data = ['choices' => [['delta' => ['content' => 'Hello']]]];

        self::assertSame('Hello', $this->extractor()->extract($data, 'choices.0.delta.content'));
    }

    #[Test]
    public function returnsNullWhenPathSegmentMissing(): void
    {
        $data = ['choices' => []];

        self::assertNull($this->extractor()->extract($data, 'choices.0.delta.content'));
    }

    #[Test]
    public function returnsNullWhenDataIsNotAnArray(): void
    {
        self::assertNull($this->extractor()->extract('not-an-array', 'choices.0'));
    }

    #[Test]
    public function returnsWholeValueWhenPathIsSingleSegment(): void
    {
        $data = ['embedding' => [0.1, 0.2]];

        self::assertSame([0.1, 0.2], $this->extractor()->extract($data, 'embedding'));
    }
}

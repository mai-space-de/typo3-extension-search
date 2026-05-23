<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Dto;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IndexingContextTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $context = new IndexingContext(
            core: 'core_en',
            batchSize: 50,
            offset: 10,
        );

        self::assertSame('core_en', $context->core);
        self::assertSame(50, $context->batchSize);
        self::assertSame(10, $context->offset);
    }

    #[Test]
    public function usesDefaultValues(): void
    {
        $context = new IndexingContext(core: 'core_de');

        self::assertSame('core_de', $context->core);
        self::assertSame(100, $context->batchSize);
        self::assertSame(0, $context->offset);
    }
}

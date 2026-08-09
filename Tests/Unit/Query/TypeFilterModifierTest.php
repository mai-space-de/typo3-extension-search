<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Query;

use Maispace\MaiSearch\Query\QueryContext;
use Maispace\MaiSearch\Query\TypeFilterModifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeFilterModifierTest extends TestCase
{
    #[Test]
    public function applyAddsTypeFilterWhenTypeIsGiven(): void
    {
        $modifier = new TypeFilterModifier('news');

        $result = $modifier->apply(new QueryContext(query: 'test'));

        self::assertSame(['type' => 'news'], $result->filters);
    }

    #[Test]
    public function applyLeavesContextUnchangedWhenTypeIsNull(): void
    {
        $modifier = new TypeFilterModifier(null);
        $context = new QueryContext(query: 'test');

        self::assertSame($context, $modifier->apply($context));
    }

    #[Test]
    public function applyLeavesContextUnchangedWhenTypeIsEmptyString(): void
    {
        $modifier = new TypeFilterModifier('');
        $context = new QueryContext(query: 'test');

        self::assertSame($context, $modifier->apply($context));
    }

    #[Test]
    public function getPriorityIsOneHundred(): void
    {
        self::assertSame(100, (new TypeFilterModifier('news'))->getPriority());
    }
}

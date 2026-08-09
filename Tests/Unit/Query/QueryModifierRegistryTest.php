<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Query;

use Maispace\MaiSearch\Query\QueryContext;
use Maispace\MaiSearch\Query\QueryModifierInterface;
use Maispace\MaiSearch\Query\QueryModifierRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryModifierRegistryTest extends TestCase
{
    private function createModifier(int $priority, callable $apply): QueryModifierInterface
    {
        return new class ($priority, $apply) implements QueryModifierInterface {
            public function __construct(
                private readonly int $priority,
                private readonly mixed $applyCallback,
            ) {}

            public function apply(QueryContext $context): QueryContext
            {
                return ($this->applyCallback)($context);
            }

            public function getPriority(): int
            {
                return $this->priority;
            }
        };
    }

    #[Test]
    public function applyAllRunsEveryRegisteredModifier(): void
    {
        $registry = new QueryModifierRegistry();
        $registry->addModifier($this->createModifier(0, static fn(QueryContext $c): QueryContext => $c->withFilter('a', '1')));
        $registry->addModifier($this->createModifier(0, static fn(QueryContext $c): QueryContext => $c->withFilter('b', '2')));

        $result = $registry->applyAll(new QueryContext(query: 'test'));

        self::assertSame(['a' => '1', 'b' => '2'], $result->filters);
    }

    #[Test]
    public function applyAllRunsModifiersInDescendingPriorityOrder(): void
    {
        $registry = new QueryModifierRegistry();
        $order = [];

        $registry->addModifier($this->createModifier(10, function (QueryContext $c) use (&$order): QueryContext {
            $order[] = 'low';

            return $c;
        }));
        $registry->addModifier($this->createModifier(100, function (QueryContext $c) use (&$order): QueryContext {
            $order[] = 'high';

            return $c;
        }));

        $registry->applyAll(new QueryContext(query: 'test'));

        self::assertSame(['high', 'low'], $order);
    }

    #[Test]
    public function getModifiersReturnsAllAddedModifiers(): void
    {
        $registry = new QueryModifierRegistry();
        $registry->addModifier($this->createModifier(0, static fn(QueryContext $c): QueryContext => $c));
        $registry->addModifier($this->createModifier(0, static fn(QueryContext $c): QueryContext => $c));

        self::assertCount(2, $registry->getModifiers());
    }

    #[Test]
    public function applyAllOnEmptyRegistryReturnsContextUnchanged(): void
    {
        $registry = new QueryModifierRegistry();
        $context = new QueryContext(query: 'test');

        self::assertSame($context, $registry->applyAll($context));
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Query;

/**
 * Registry for query modifiers.
 */
class QueryModifierRegistry
{
    /**
     * @var QueryModifierInterface[]
     */
    private array $modifiers = [];

    public function addModifier(QueryModifierInterface $modifier): void
    {
        $this->modifiers[] = $modifier;
        usort($this->modifiers, static fn(QueryModifierInterface $a, QueryModifierInterface $b): int => $b->getPriority() <=> $a->getPriority());
    }

    public function applyAll(QueryContext $context): QueryContext
    {
        foreach ($this->modifiers as $modifier) {
            $context = $modifier->apply($context);
        }

        return $context;
    }

    /**
     * @return QueryModifierInterface[]
     */
    public function getModifiers(): array
    {
        return $this->modifiers;
    }
}

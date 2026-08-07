<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Query;

/**
 * Applies type filter to the query.
 */
class TypeFilterModifier implements QueryModifierInterface
{
    public function __construct(
        private readonly ?string $type = null,
    ) {}

    public function apply(QueryContext $context): QueryContext
    {
        if ($this->type === null || $this->type === '') {
            return $context;
        }

        return $context->withFilter('type', $this->type);
    }

    public function getPriority(): int
    {
        return 100;
    }
}

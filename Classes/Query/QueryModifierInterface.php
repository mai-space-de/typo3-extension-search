<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Query;

/**
 * Modifies a query context before execution.
 */
interface QueryModifierInterface
{
    public function apply(QueryContext $context): QueryContext;

    public function getPriority(): int;
}

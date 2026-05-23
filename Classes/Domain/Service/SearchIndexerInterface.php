<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Model\IndexingContext;

interface SearchIndexerInterface
{
    public function getType(): string;

    public function indexAll(IndexingContext $context): void;

    public function indexRecord(object $record, IndexingContext $context): void;

    public function removeRecord(int $uid, string $table): void;

    public function getBoost(string $type): float;

    public function supports(string $table): bool;
}

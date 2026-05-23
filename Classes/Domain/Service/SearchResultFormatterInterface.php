<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;

interface SearchResultFormatterInterface
{
    public function getType(): string;

    public function formatResult(array $solrDoc): SearchResult;

    public function getIcon(string $type): string;
}

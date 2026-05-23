<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use TYPO3\CMS\Core\SingletonInterface;

class ResultFormatterRegistry implements SingletonInterface
{
    private array $formatters = [];

    public function addFormatter(SearchResultFormatterInterface $formatter): void
    {
        $this->formatters[$formatter->getType()] = $formatter;
    }

    public function getFormatter(string $type): ?SearchResultFormatterInterface
    {
        return $this->formatters[$type] ?? null;
    }

    /**
     * @return SearchResultFormatterInterface[]
     */
    public function getAll(): array
    {
        return array_values($this->formatters);
    }
}

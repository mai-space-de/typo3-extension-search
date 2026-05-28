<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\ResultFormatter;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;

abstract class AbstractResultFormatter implements SearchResultFormatterInterface
{
    public function formatResult(array $solrDoc): SearchResult
    {
        return new SearchResult(
            type: $this->getType(),
            title: $solrDoc['title_s'] ?? '',
            snippet: $this->buildSnippet($solrDoc),
            url: $solrDoc['url_s'] ?? '',
            icon: $this->getIcon($this->getType()),
            date: $this->parseDate($solrDoc),
            score: (float) ($solrDoc['score'] ?? 0.0),
            rootline: $this->parseRootline($solrDoc),
        );
    }

    protected function buildSnippet(array $solrDoc): string
    {
        $content = $solrDoc['content_t'] ?? '';
        return mb_substr(strip_tags($content), 0, 200);
    }

    protected function parseDate(array $solrDoc): ?\DateTime
    {
        if (empty($solrDoc['crdate_dt'])) {
            return null;
        }

        try {
            return new \DateTime($solrDoc['crdate_dt']);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return string[]|null
     */
    protected function parseRootline(array $solrDoc): ?array
    {
        if (empty($solrDoc['rootline_s'])) {
            return null;
        }

        $parts = explode(' | ', (string) $solrDoc['rootline_s']);
        $filtered = array_values(array_filter(array_map('trim', $parts)));

        return $filtered !== [] ? $filtered : null;
    }
}

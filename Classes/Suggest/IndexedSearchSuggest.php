<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Suggest;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\IndexedSearch\Domain\Repository\IndexSearchRepository;
use TYPO3\CMS\IndexedSearch\Lexer;
use TYPO3\CMS\IndexedSearch\Utility\IndexedSearchUtility;

/**
 * indexed_search has no prefix-matching suggest API (TYPO3 core indexes rendered
 * page HTML by phash, not structured title fields) — this runs the same query
 * IndexedSearchBackend does and returns the top result titles as a degraded-
 * capability approximation, consistent with that backend's other limitations.
 */
final class IndexedSearchSuggest implements SuggestInterface
{
    public function __construct(
        private readonly IndexSearchRepository $searchRepository,
        private readonly Lexer $lexer,
    ) {}

    public function getIdentifier(): string
    {
        return 'indexed_search';
    }

    public function getSuggestions(string $query, int $limit = 10, ?SiteLanguage $language = null): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            $this->searchRepository->initialize(
                settings: [],
                searchData: [
                    'languageUid' => $language?->getLanguageId() ?? 0,
                    'pointer' => 0,
                    'numberOfResults' => $limit,
                ],
                externalParsers: [],
                searchRootPageIdList: -1,
            );

            $result = $this->searchRepository->doSearch($this->buildSearchWords($query), -1);

            if ($result === false) {
                return [];
            }

            $suggestions = [];
            foreach ($result['resultRows'] as $row) {
                $title = $row['item_title'] ?? null;
                if ($title !== null && is_string($title) && $title !== '') {
                    $suggestions[] = $title;
                }
            }

            return $suggestions;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Mirrors IndexedSearchBackend::buildSearchWords().
     *
     * @return list<array{sword: string, oper: string}>
     */
    private function buildSearchWords(string $query): array
    {
        $exploded = IndexedSearchUtility::getExplodedSearchString($query, 'AND', [
            ['+', 'AND'],
            ['|', 'OR'],
            ['-', 'AND NOT'],
        ]);

        $words = [];
        foreach ($exploded as $wordDef) {
            if (str_contains($wordDef['sword'], ' ')) {
                $words[] = $wordDef;

                continue;
            }

            foreach ($this->lexer->split2Words($wordDef['sword']) as $word) {
                $words[] = ['sword' => $word, 'oper' => $wordDef['oper']];
            }
        }

        return $words;
    }
}

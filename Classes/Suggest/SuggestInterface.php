<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Suggest;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Provides search suggestions/autocomplete functionality.
 */
interface SuggestInterface
{
    /**
     * Stable identifier matching SearchBackendInterface::getIdentifier() — lets
     * SuggestRegistry resolve the suggest implementation for whichever backend is
     * currently active, e.g. 'solr', 'meilisearch', 'indexed_search'.
     */
    public function getIdentifier(): string;

    /**
     * Get search suggestions for a partial query.
     *
     * @param string $query Partial search query
     * @param int $limit Maximum number of suggestions to return
     * @param ?SiteLanguage $language Current site language for backend/core selection —
     *        mirrors SearchBackendInterface::search()'s $request->language. Without it,
     *        implementations fall back to the configured default core/index, which for a
     *        multi-language site silently misses every non-default-language document.
     * @return list<string> List of suggested search terms
     */
    public function getSuggestions(string $query, int $limit = 10, ?SiteLanguage $language = null): array;
}

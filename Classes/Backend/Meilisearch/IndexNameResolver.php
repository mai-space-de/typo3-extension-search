<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

/**
 * Builds a Meilisearch index UID per language, e.g. 'mai_search_de' /
 * 'mai_search' (no language). One index holds every record type, with `type`
 * as a filterable attribute — mirrors SolrBackend's per-language-core shape
 * (one query, all types, type as a facet) rather than mai_meilisearch's
 * original one-index-per-type-per-language convention, so MeilisearchBackend
 * can stay a single simple query like SolrBackend's.
 */
final class IndexNameResolver
{
    private const string PREFIX = 'mai_search';

    public function forLanguage(?string $languageCode): string
    {
        return $languageCode !== null && $languageCode !== '' ? self::PREFIX . '_' . $languageCode : self::PREFIX;
    }
}

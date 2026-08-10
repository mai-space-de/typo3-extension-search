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
    private const string CHUNK_PREFIX = 'mai_search_chunks';

    public function forLanguage(?string $languageCode): string
    {
        return $languageCode !== null && $languageCode !== '' ? self::PREFIX . '_' . $languageCode : self::PREFIX;
    }

    /**
     * Separate index from forLanguage() (SPEC-v3-caching-chunking.md Phase E) —
     * once an index has an embedder configured, Meilisearch requires every
     * document written to it to carry a vector, which would break every
     * ordinary (non-chunk) document write if chunks shared the main index.
     */
    public function forChunks(?string $languageCode): string
    {
        return $languageCode !== null && $languageCode !== '' ? self::CHUNK_PREFIX . '_' . $languageCode : self::CHUNK_PREFIX;
    }
}

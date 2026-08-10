<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Caches query-time embedding vectors so the same query text is never sent to the
 * embedding provider twice — neither across requests (repeated/FAQ-like questions)
 * nor within a single request, where SolrBackend's hybrid KNN rerank and
 * RagContextResolver's wider rerank query both embed the identical query text
 * (SPEC-v3-caching-chunking.md, gaps G2/G3).
 *
 * Deliberately separate from document/chunk embedding: a query string is short and
 * genuinely repeats across visitors, so a flat TTL cache fits. Document content is
 * large and rarely repeats verbatim, where a content-hash-gated skip (SPEC-v3.md
 * Phase B) is the better fit — this service is not used on the indexing path.
 *
 * No content-based invalidation is needed here: embedding a fixed string with a
 * fixed model is a pure, deterministic function of its input, with no dependency on
 * any indexed document. The active embedding provider's identifier is baked into
 * the cache key instead of being invalidated explicitly — switching
 * `embeddingProvider` naturally misses the cache rather than needing an
 * invalidation call, the same way AnswerCacheService's context-URL tags let content
 * changes invalidate themselves rather than being swept externally.
 */
class QueryEmbeddingCacheService implements SingletonInterface
{
    private const string CACHE_IDENTIFIER = 'maisearch_query_embedding';

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly CacheManager $cacheManager,
        private readonly RagConfiguration $ragConfiguration,
    ) {}

    private function getCache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }

    /**
     * @return list<float>
     * @throws \RuntimeException propagated from the active provider on failure —
     *   callers that already catch that for the uncached call (e.g.
     *   SolrBackend::applyHybridKnnReRank()) don't need to change their handling.
     */
    public function embed(string $query): array
    {
        $provider = $this->providerRegistry->getActiveEmbeddingProvider();
        $cacheKey = $this->buildCacheKey($provider->getIdentifier(), $query);

        $cached = $this->getCache()->get($cacheKey);
        if (is_array($cached)) {
            /** @var list<float> $cached */
            return $cached;
        }

        $vector = $provider->embed($query);

        // An empty vector means the provider failed to produce one (malformed
        // response, etc.) — caching that would keep skipping KNN rerank for this
        // exact query for the full TTL even after the provider recovers.
        if ($vector !== []) {
            $this->getCache()->set($cacheKey, $vector, [], $this->ragConfiguration->getQueryEmbeddingCacheTtl());
        }

        return $vector;
    }

    private function buildCacheKey(string $providerIdentifier, string $query): string
    {
        $normalizedQuery = mb_strtolower(trim($query));

        return 'query_embedding_' . hash('sha256', $providerIdentifier . '|' . $normalizedQuery);
    }
}

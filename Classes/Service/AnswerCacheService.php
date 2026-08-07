<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Caches synthesized RAG answers to avoid redundant LLM calls.
 *
 * Cache keys are based on a hash of the query + resolved context IDs + conversation history,
 * ensuring that identical requests return cached answers while content changes invalidate
 * the cache appropriately.
 */
final class AnswerCacheService
{
    private const string CACHE_IDENTIFIER_PREFIX = 'maisearch_answer_';
    private const int DEFAULT_TTL = 3600; // 1 hour

    public function __construct(
        private readonly FrontendInterface $cache,
    ) {}

    /**
     * @param list<string> $contextIds
     * @param list<array{role: string, content: string}> $history
     */
    public function get(string $query, array $contextIds, array $history): ?string
    {
        $cacheKey = $this->buildCacheKey($query, $contextIds, $history);

        $cached = $this->cache->get($cacheKey);

        return $cached !== false ? (string) $cached : null;
    }

    /**
     * @param list<string> $contextIds
     * @param list<array{role: string, content: string}> $history
     */
    public function set(string $query, array $contextIds, array $history, string $answer, int $ttl = self::DEFAULT_TTL): void
    {
        $cacheKey = $this->buildCacheKey($query, $contextIds, $history);
        $this->cache->set($cacheKey, $answer, ['maisearch_answer'], $ttl);
    }

    /**
     * Invalidate all cached answers for a specific content hash.
     */
    public function invalidateByContentHash(string $contentHash): void
    {
        $this->cache->flushByTag('content_hash_' . $contentHash);
    }

    /**
     * Invalidate all cached answers.
     */
    public function invalidateAll(): void
    {
        $this->cache->flushByTag('maisearch_answer');
    }

    /**
     * @param list<string> $contextIds
     * @param list<array{role: string, content: string}> $history
     */
    private function buildCacheKey(string $query, array $contextIds, array $history): string
    {
        $normalizedQuery = mb_strtolower(trim($query));
        $contextKey = implode(',', $contextIds);
        $historyKey = json_encode($history, JSON_THROW_ON_ERROR);

        $hash = hash('sha256', $normalizedQuery . '|' . $contextKey . '|' . $historyKey);

        return self::CACHE_IDENTIFIER_PREFIX . $hash;
    }
}

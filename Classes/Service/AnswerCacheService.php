<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Caches synthesized RAG answers to avoid redundant LLM calls.
 *
 * Cache keys are based on a hash of the query + resolved context IDs + conversation history,
 * ensuring that identical requests return cached answers while content changes invalidate
 * the cache appropriately.
 *
 * Invalidation is tagged by context document identity (each context result's URL — the
 * only stable identifier SearchResult exposes, since its DTO shape is a frozen external
 * contract), not by content-hash value: a content hash rotates every time a document
 * changes, so tagging cache entries with today's hash would never match the value an
 * invalidation call fires with tomorrow. AbstractIndexer calls invalidateForUrl() with
 * the same URL a SearchResult later exposes for that document, only when
 * ContentHashService confirms the content actually changed.
 */
final class AnswerCacheService
{
    private const string CACHE_IDENTIFIER_PREFIX = 'maisearch_answer_';
    private const string CONTEXT_TAG_PREFIX = 'context_';
    private const int DEFAULT_TTL = 3600; // 1 hour

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    private function getCache(): FrontendInterface
    {
        return $this->cacheManager->getCache('maisearch_answer');
    }

    /**
     * @param list<string> $contextIds
     * @param list<array{role: string, content: string}> $history
     */
    public function get(string $query, array $contextIds, array $history): ?string
    {
        $cacheKey = $this->buildCacheKey($query, $contextIds, $history);

        $cached = $this->getCache()->get($cacheKey);

        return $cached !== false ? (string) $cached : null;
    }

    /**
     * @param list<string> $contextIds
     * @param list<array{role: string, content: string}> $history
     */
    public function set(string $query, array $contextIds, array $history, string $answer, int $ttl = self::DEFAULT_TTL): void
    {
        $cacheKey = $this->buildCacheKey($query, $contextIds, $history);

        $tags = ['maisearch_answer'];
        foreach ($contextIds as $contextId) {
            $tags[] = self::CONTEXT_TAG_PREFIX . md5($contextId);
        }

        $this->getCache()->set($cacheKey, $answer, $tags, $ttl);
    }

    /**
     * Invalidate all cached answers that used the given document (by URL) as context.
     */
    public function invalidateForUrl(string $url): void
    {
        $this->getCache()->flushByTag(self::CONTEXT_TAG_PREFIX . md5($url));
    }

    /**
     * Invalidate all cached answers.
     */
    public function invalidateAll(): void
    {
        $this->getCache()->flushByTag('maisearch_answer');
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

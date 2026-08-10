<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Typed accessor for the ragRerank…/ragStreaming…/ragHistory… Extension
 * Configuration settings (see ext_conf_template.txt).
 *
 * These settings deliberately live in Extension Configuration rather than
 * TypoScript plugin settings (unlike plugin.tx_maisearch.settings.ragEnabled) —
 * SearchStreamMiddleware, a plain PSR-15 middleware registered before the
 * Extbase/TypoScript frontend context is resolved, needs to read them too, and
 * TypoScript plugin settings aren't reliably available there. ExtensionConfiguration
 * is globally available regardless of request stage, so both SearchController and
 * SearchStreamMiddleware share this one accessor. See SPEC-v2.md Phase 7 addendum.
 */
class RagConfiguration
{
    private const int DEFAULT_RERANK_WIDTH = 20;
    private const int DEFAULT_TOP_N = 5;
    private const int DEFAULT_HISTORY_MAX_TURNS = 6;
    private const int DEFAULT_QUERY_EMBEDDING_CACHE_TTL = 86400;
    private const int DEFAULT_ANSWER_CACHE_TTL = 3600;
    private const int DEFAULT_CHUNK_SIZE = 500;
    private const int DEFAULT_CHUNK_OVERLAP = 50;

    /**
     * @var array<string, mixed>
     */
    private readonly array $settings;

    public function __construct(?ExtensionConfiguration $extensionConfiguration = null)
    {
        $extensionConfiguration ??= GeneralUtility::makeInstance(ExtensionConfiguration::class);

        try {
            $this->settings = $extensionConfiguration->get('mai_search');
        } catch (\Exception) {
            $this->settings = [];
        }
    }

    public function isRerankEnabled(): bool
    {
        return (bool) ($this->settings['ragRerankEnabled'] ?? false);
    }

    public function getRerankWidth(): int
    {
        return max(1, (int) ($this->settings['ragRerankWidth'] ?? self::DEFAULT_RERANK_WIDTH));
    }

    public function getTopN(): int
    {
        return max(1, (int) ($this->settings['ragTopN'] ?? self::DEFAULT_TOP_N));
    }

    public function isStreamingEnabled(): bool
    {
        return (bool) ($this->settings['ragStreamingEnabled'] ?? false);
    }

    public function getHistoryMaxTurns(): int
    {
        return max(0, (int) ($this->settings['ragHistoryMaxTurns'] ?? self::DEFAULT_HISTORY_MAX_TURNS));
    }

    /**
     * TTL for QueryEmbeddingCacheService entries (SPEC-v3.md Phase A). Query
     * embeddings are a deterministic function of (provider, text), so — unlike
     * the answer cache — a flat TTL is the only invalidation strategy needed.
     */
    public function getQueryEmbeddingCacheTtl(): int
    {
        return max(0, (int) ($this->settings['queryEmbeddingCacheTtl'] ?? self::DEFAULT_QUERY_EMBEDDING_CACHE_TTL));
    }

    /**
     * TTL for AnswerCacheService entries (SPEC-v3.md Phase C). Was a hardcoded
     * class constant on AnswerCacheService; moved here so it's configurable
     * alongside the other RAG tuning knobs.
     */
    public function getAnswerCacheTtl(): int
    {
        return max(0, (int) ($this->settings['ragAnswerCacheTtl'] ?? self::DEFAULT_ANSWER_CACHE_TTL));
    }

    /**
     * Maximum characters per chunk fed to ContentChunkerService
     * (SPEC-v3-caching-chunking.md Phase D/G).
     */
    public function getChunkSize(): int
    {
        return max(1, (int) ($this->settings['chunkSize'] ?? self::DEFAULT_CHUNK_SIZE));
    }

    /**
     * Overlapping characters between consecutive chunks — see
     * ContentChunkerService::chunk() for why this must stay below chunk size.
     */
    public function getChunkOverlap(): int
    {
        $overlap = max(0, (int) ($this->settings['chunkOverlap'] ?? self::DEFAULT_CHUNK_OVERLAP));

        return min($overlap, $this->getChunkSize() - 1);
    }
}

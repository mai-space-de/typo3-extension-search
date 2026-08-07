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
}

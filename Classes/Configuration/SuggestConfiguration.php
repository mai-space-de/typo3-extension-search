<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Typed accessor for the searchSuggest… Extension Configuration settings
 * (see ext_conf_template.txt).
 *
 * Lives in Extension Configuration rather than TypoScript plugin settings for
 * the same reason RagConfiguration does: SearchSuggestMiddleware is a plain
 * PSR-15 middleware registered before the Extbase/TypoScript frontend context
 * is resolved (a real requirement discovered by testing suggestAction() as a
 * normal Extbase action — the returned JsonResponse got wrapped by the page
 * cache/rendering pipeline instead of replacing it, exactly the class of bug
 * SearchStreamMiddleware already exists to avoid for streaming; see
 * SearchSuggestMiddleware's docblock), so ExtensionConfiguration is the only
 * accessor reliably available at that request stage.
 */
class SuggestConfiguration
{
    private const int DEFAULT_MIN_CHARS = 2;
    private const int DEFAULT_LIMIT = 8;

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

    public function isEnabled(): bool
    {
        return (bool) ($this->settings['searchSuggestEnabled'] ?? false);
    }

    public function getMinChars(): int
    {
        return max(1, (int) ($this->settings['searchSuggestMinChars'] ?? self::DEFAULT_MIN_CHARS));
    }

    public function getLimit(): int
    {
        return max(1, (int) ($this->settings['searchSuggestLimit'] ?? self::DEFAULT_LIMIT));
    }
}

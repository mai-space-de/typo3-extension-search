<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Suggest\SuggestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Resolves the active SuggestInterface implementation from the same
 * `activeBackend` extension configuration setting BackendRegistry reads
 * (SPEC-v2.md Phase 8) — suggest always mirrors whichever backend is actually
 * serving search, never a separately-configured choice.
 *
 * Unlike BackendRegistry, getActive() is nullable rather than throwing/falling
 * back to Solr: suggest is a best-effort UX enhancement, not core search
 * functionality — SearchController already treats a null SuggestInterface as
 * "suggestions disabled" (see its optional constructor argument).
 */
class SuggestRegistry implements SingletonInterface
{
    private const string FALLBACK_IDENTIFIER = 'solr';

    /**
     * @var array<string, SuggestInterface>
     */
    private array $suggesters = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function addSuggest(SuggestInterface $suggest): void
    {
        $this->suggesters[$suggest->getIdentifier()] = $suggest;
    }

    public function getActive(): ?SuggestInterface
    {
        $identifier = $this->resolveActiveIdentifier();

        return $this->suggesters[$identifier] ?? $this->suggesters[self::FALLBACK_IDENTIFIER] ?? null;
    }

    private function resolveActiveIdentifier(): string
    {
        try {
            $settings = $this->extensionConfiguration->get('mai_search');
        } catch (\Exception) {
            return self::FALLBACK_IDENTIFIER;
        }

        $identifier = $settings['activeBackend'] ?? self::FALLBACK_IDENTIFIER;

        return is_string($identifier) && $identifier !== '' ? $identifier : self::FALLBACK_IDENTIFIER;
    }
}

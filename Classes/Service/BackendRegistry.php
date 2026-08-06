<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Resolves the active SearchBackendInterface implementation from the
 * `activeBackend` extension configuration setting (SPEC-v2.md Phase 1).
 *
 * Falls back to 'solr' when the configured backend isn't registered — e.g.
 * `activeBackend = meilisearch` before the Meilisearch backend exists yet
 * (Phase 2), so misconfiguration degrades to the known-good default instead
 * of breaking search outright.
 */
class BackendRegistry implements SingletonInterface
{
    private const string FALLBACK_IDENTIFIER = 'solr';

    /**
     * @var array<string, SearchBackendInterface>
     */
    private array $backends = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function addBackend(SearchBackendInterface $backend): void
    {
        $this->backends[$backend->getIdentifier()] = $backend;
    }

    /**
     * @throws \RuntimeException When neither the configured backend nor the fallback is registered
     */
    public function getActive(): SearchBackendInterface
    {
        $identifier = $this->resolveActiveIdentifier();

        return $this->backends[$identifier]
            ?? $this->backends[self::FALLBACK_IDENTIFIER]
            ?? throw new \RuntimeException(
                sprintf(
                    'No search backend registered for "%s" and no "%s" fallback is available.',
                    $identifier,
                    self::FALLBACK_IDENTIFIER,
                ),
                1748900000,
            );
    }

    public function get(string $identifier): ?SearchBackendInterface
    {
        return $this->backends[$identifier] ?? null;
    }

    /**
     * @return list<SearchBackendInterface>
     */
    public function getAll(): array
    {
        return array_values($this->backends);
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

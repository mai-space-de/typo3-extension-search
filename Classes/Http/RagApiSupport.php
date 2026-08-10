<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Http;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Shared parsing for the RAG JSON/SSE API middlewares (answer + stream).
 */
final class RagApiSupport
{
    /**
     * @param list<array<string, mixed>>|mixed $raw
     * @return SearchResult[]
     */
    public function parseContext(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $results = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $results[] = new SearchResult(
                type: (string) ($item['type'] ?? ''),
                title: (string) ($item['title'] ?? ''),
                snippet: (string) ($item['snippet'] ?? ''),
                url: $url,
                icon: (string) ($item['icon'] ?? ''),
                date: null,
                score: (float) ($item['score'] ?? 0.0),
            );
        }

        return $results;
    }

    /**
     * @param SearchResult[] $results
     * @return list<array{type: string, title: string, snippet: string, url: string, icon: string, score: float}>
     */
    public function serializeContext(array $results, int $limit): array
    {
        $payload = [];
        foreach (array_slice($results, 0, max(0, $limit)) as $result) {
            $payload[] = [
                'type' => $result->type,
                'title' => $result->title,
                'snippet' => $result->snippet,
                'url' => $result->url,
                'icon' => $result->icon,
                'score' => $result->score,
            ];
        }

        return $payload;
    }

    /**
     * @param SearchResult[] $context
     * @return list<array{title: string, url: string, type: string, score: float}>
     */
    public function buildSources(array $context): array
    {
        return array_map(
            static fn(SearchResult $result): array => [
                'title' => $result->title,
                'url' => $result->url,
                'type' => $result->type,
                'score' => $result->score,
            ],
            $context,
        );
    }

    public function resolveLanguage(ServerRequestInterface $request, ?string $locale = null): ?SiteLanguage
    {
        $locale = trim((string) $locale);
        if ($locale !== '') {
            $site = $request->getAttribute('site');
            if ($site instanceof Site) {
                foreach ($site->getLanguages() as $language) {
                    if ($this->languageMatchesLocale($language, $locale)) {
                        return $language;
                    }
                }
            }
        }

        $language = $request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }

    public function languageCode(?SiteLanguage $language): string
    {
        if ($language === null) {
            return '';
        }

        return $language->getLocale()->getLanguageCode();
    }

    private function languageMatchesLocale(SiteLanguage $language, string $locale): bool
    {
        $normalized = strtolower(str_replace('_', '-', $locale));
        $localeString = strtolower(str_replace('_', '-', (string) $language->getLocale()));
        $candidates = array_filter([
            $localeString,
            strtolower($language->getLocale()->getLanguageCode()),
            strtolower($language->getHreflang()),
        ]);

        return in_array($normalized, $candidates, true)
            || in_array(explode('-', $normalized)[0], $candidates, true);
    }
}
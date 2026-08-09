<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Suggest;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class MeilisearchSuggest implements SuggestInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly IndexNameResolver $indexNameResolver,
    ) {}

    public function getIdentifier(): string
    {
        return 'meilisearch';
    }

    public function getSuggestions(string $query, int $limit = 10, ?SiteLanguage $language = null): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            $indexUid = $this->indexNameResolver->forLanguage($language?->getLocale()->getLanguageCode());

            $response = $this->connectionFactory->getClient()->search($indexUid, $query, [
                'limit' => $limit,
                'attributesToRetrieve' => ['title'],
            ]);

            $suggestions = [];
            foreach ($response->hits as $hit) {
                $title = $hit['title'] ?? null;
                if ($title !== null && is_string($title)) {
                    $suggestions[] = $title;
                }
            }

            return $suggestions;
        } catch (\Throwable) {
            return [];
        }
    }
}

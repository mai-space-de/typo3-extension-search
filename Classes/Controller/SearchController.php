<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Controller;

use Maispace\MaiSearch\Domain\Service\SearchService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SearchController extends ActionController
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function formAction(): ResponseInterface
    {
        $this->view->assign('ragEnabled', $this->resolveRagEnabled());

        return $this->htmlResponse();
    }

    public function resultsAction(string $query = ''): ResponseInterface
    {
        $ragEnabled = $this->resolveRagEnabled();

        if (trim($query) === '') {
            $this->view->assign('ragEnabled', $ragEnabled);
            return $this->htmlResponse();
        }

        $language = $this->resolveCurrentLanguage();

        $results = $this->searchService->search(
            $query,
            (int) ($this->settings['resultsPerPage'] ?? 20),
            0,
            $language,
        );

        $this->view->assignMultiple([
            'query' => $query,
            'results' => $results,
            'count' => count($results),
            'ragEnabled' => $ragEnabled,
        ]);

        return $this->htmlResponse();
    }

    /**
     * Resolve the current language from the request attribute.
     * Falls back to null when no language is available (CLI or backend context).
     */
    private function resolveCurrentLanguage(): ?SiteLanguage
    {
        $language = $this->request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }

    /**
     * Resolve the RAG-enabled flag from TypoScript settings.
     * Defaults to disabled (false) when the setting is not configured.
     */
    private function resolveRagEnabled(): bool
    {
        return (bool) ($this->settings['ragEnabled'] ?? false);
    }
}

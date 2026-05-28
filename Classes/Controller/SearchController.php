<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Controller;

use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SearchController extends ActionController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly SearchSynthesisService $synthesisService,
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
            $ragEnabled,
        );

        $synthesisResult = $ragEnabled && $results !== []
            ? $this->synthesisService->synthesise($query, $results, $ragEnabled)
            : null;

        $this->view->assignMultiple([
            'query' => $query,
            'results' => $results,
            'count' => count($results),
            'ragEnabled' => $ragEnabled,
            'synthesisResult' => $synthesisResult,
        ]);

        return $this->htmlResponse();
    }

    private function resolveCurrentLanguage(): ?SiteLanguage
    {
        $language = $this->request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }

    private function resolveRagEnabled(): bool
    {
        return (bool) ($this->settings['ragEnabled'] ?? false);
    }
}

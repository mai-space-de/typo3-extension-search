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

    public function resultsAction(string $query = '', string $type = '', int $page = 1): ResponseInterface
    {
        $ragEnabled = $this->resolveRagEnabled();
        $perPage = max(1, (int) ($this->settings['resultsPerPage'] ?? 20));
        $page = max(1, $page);
        $typeFilter = $type !== '' ? $type : null;

        if (trim($query) === '') {
            $this->view->assignMultiple([
                'ragEnabled' => $ragEnabled,
                'query' => $query,
                'type' => $typeFilter,
                'page' => $page,
            ]);

            return $this->htmlResponse();
        }

        $language = $this->resolveCurrentLanguage();
        $offset = ($page - 1) * $perPage;

        $resultPage = $this->searchService->search(
            $query,
            $perPage,
            $offset,
            $language,
            $ragEnabled,
            type: $typeFilter,
        );

        $synthesisResult = $ragEnabled && $resultPage->results !== []
            ? $this->synthesisService->synthesise($query, $resultPage->results, $ragEnabled)
            : null;

        $this->view->assignMultiple([
            'query' => $query,
            'type' => $typeFilter,
            'results' => $resultPage->results,
            'count' => $resultPage->total,
            'types' => $resultPage->types,
            'typeTotal' => array_sum($resultPage->types),
            'page' => $resultPage->page,
            'perPage' => $resultPage->perPage,
            'totalPages' => $resultPage->getTotalPages(),
            'prevPage' => $resultPage->page > 1 ? $resultPage->page - 1 : null,
            'nextPage' => $resultPage->page < $resultPage->getTotalPages() ? $resultPage->page + 1 : null,
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

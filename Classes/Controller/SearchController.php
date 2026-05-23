<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Controller;

use Maispace\MaiSearch\Domain\Service\SearchService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SearchController extends ActionController
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function formAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

    public function resultsAction(string $query = ''): ResponseInterface
    {
        if (trim($query) === '') {
            return $this->htmlResponse();
        }

        $results = $this->searchService->search(
            $query,
            (int) ($this->settings['resultsPerPage'] ?? 20),
        );

        $this->view->assignMultiple([
            'query' => $query,
            'results' => $results,
            'count' => count($results),
        ]);

        return $this->htmlResponse();
    }
}

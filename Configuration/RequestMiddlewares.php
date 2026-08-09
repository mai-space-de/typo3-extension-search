<?php

declare(strict_types=1);

use Maispace\MaiSearch\Middleware\SearchStreamMiddleware;
use Maispace\MaiSearch\Middleware\SearchSuggestMiddleware;

return [
    'frontend' => [
        // 'after: site' alone leaves TYPO3's dependency-ordering free to place
        // these anywhere afterward, including after typo3/cms-frontend/page-resolver
        // — which resolves the request path to a page slug and 404s for any path
        // that isn't one, short-circuiting before this middleware would ever run.
        // 'before: page-resolver' is required so /api/search/* is intercepted first.
        'maispace/mai-search/search-stream-api' => [
            'target' => SearchStreamMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'maispace/mai-search/search-suggest-api' => [
            'target' => SearchSuggestMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];

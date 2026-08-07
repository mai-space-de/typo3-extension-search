<?php

declare(strict_types=1);

use Maispace\MaiSearch\Middleware\SearchStreamMiddleware;

return [
    'frontend' => [
        'maispace/mai-search/search-stream-api' => [
            'target' => SearchStreamMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];

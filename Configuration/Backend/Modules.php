<?php

declare(strict_types=1);

use Maispace\MaiSearch\Controller\Backend\SearchBackendController;

return [
    'mai_search' => [
        'parent' => 'web',
        'access' => 'user',
        'workspaces' => 'online',
        'path' => '/module/mai-search',
        'iconIdentifier' => 'mai-backend-module',
        'labels' => 'LLL:EXT:mai_search/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'MaiSearch',
        'controllerActions' => [
            SearchBackendController::class => ['index', 'reindex', 'clear'],
        ],
    ],
];

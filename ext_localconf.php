<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['mai_search']
    = \Maispace\MaiSearch\Hook\DataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['mai_search']
    = \Maispace\MaiSearch\Hook\DataHandlerHook::class;

ExtensionUtility::configurePlugin(
    'MaiSearch',
    'Search',
    [
        \Maispace\MaiSearch\Controller\SearchController::class => 'results',
    ],
    [
        // Dynamic Solr results; GET query/type/page params are cacheHash-excluded below.
        \Maispace\MaiSearch\Controller\SearchController::class => 'results',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
);

// Allow all GET plugin parameters (form submit, filters, pagination) without cHash.
// f:form method="get" still emits __referrer / __trustedProperties — exclude the whole namespace.
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = array_values(array_unique(array_merge(
    (array) ($GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] ?? []),
    [
        '^tx_maisearch_search',
    ],
)));

ExtensionManagementUtility::addTypoScript(
    'mai_search',
    'setup',
    "@import 'EXT:mai_search/Configuration/TypoScript/setup.typoscript'",
);

ExtensionManagementUtility::addTypoScript(
    'mai_search',
    'constants',
    "@import 'EXT:mai_search/Configuration/TypoScript/constants.typoscript'",
);

// Register cache for RAG answer caching (Phase 7)
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['maisearch_answer'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['maisearch_answer'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => [
            'defaultLifetime' => 3600, // 1 hour
        ],
        'groups' => ['pages', 'system'],
    ];
}

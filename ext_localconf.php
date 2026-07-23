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
        \Maispace\MaiSearch\Controller\SearchController::class => 'form, results',
    ],
    [
        // results must not be page-cached: POST redirects omit the query in the
        // URL, so a shared cHash would otherwise serve the previous search.
        \Maispace\MaiSearch\Controller\SearchController::class => 'results',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
);

// Allow GET search forms to append query/type/page without invalidating cHash.
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = array_values(array_unique(array_merge(
    (array) ($GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] ?? []),
    [
        'tx_maisearch_search[query]',
        'tx_maisearch_search[type]',
        'tx_maisearch_search[page]',
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

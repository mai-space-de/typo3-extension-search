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
    [],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
);

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

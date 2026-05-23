<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

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

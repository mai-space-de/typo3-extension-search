<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Maispace\MaiBase\TableConfigurationArray\Helper;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

$lang = Helper::localLangHelperFactory('mai_search', 'Default/locallang_tca.xlf');

ExtensionUtility::registerPlugin(
    'MaiSearch',
    'Search',
    $lang('plugin.search.title'),
    'mai-search',
    'maispace_plugins_interactive',
);

<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Maispace\MaiBase\TableConfigurationArray\CType;
use Maispace\MaiBase\TableConfigurationArray\Helper;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

$lang = Helper::localLangHelperFactory('mai_search', 'Default/locallang_tca.xlf');

ExtensionUtility::registerPlugin(
    'MaiSearch',
    'Search',
    $lang('plugin.search.title'),
    'mai-content',
    'maispace_feature',
);

(new CType('maisearch_search', $lang('ctype.search'), 'mai-content'))
    ->addDefaultHeaderPalette()
    ->addDefaultLanguageTab()
    ->addDefaultAccessTab()
    ->setGroup('maispace_feature')
    ->register();

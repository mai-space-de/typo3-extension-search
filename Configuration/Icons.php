<?php

declare(strict_types=1);

$allIcons = 'EXT:mai_base/Resources/Public/Icons/all-icons/';

return [
    'ext-maispace-mai_search' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => 'EXT:mai_search/Resources/Public/Icons/Extension.svg',
    ],
    'mai-search' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => $allIcons . 'interface-search--glass-search-magnifying--Streamline-Core.svg',
    ],
    'mai-search-module' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => $allIcons . 'programming-browser-search--search-window-glass-app-code-programming-query-find-magnifying-apps--Streamline-Core.svg',
    ],
];

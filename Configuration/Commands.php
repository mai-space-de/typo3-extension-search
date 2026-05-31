<?php

use Maispace\MaiSearch\Command\IndexCommand;
use TYPO3\CMS\Core\Utility\GeneralUtility;

return [
    'mai-search:index' => [
        'class' => IndexCommand::class,
        'schedulable' => true,
        'description' => 'Reindex all content into Solr search indexes',
    ],
];

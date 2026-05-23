<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Mai Search',
    'description' => 'Frontend search integration with indexing, faceted filtering, and multilingual support.',
    'category' => 'module',
    'author' => 'Maispace',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.1.0-14.99.99',
            'solr' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];

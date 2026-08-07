<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use Maispace\MaiSearch\Extraction\ExtractorRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class TextExtractionStatus implements StatusProviderInterface
{
    public function getTitle(): string
    {
        return 'Text Extraction';
    }

    public function getStatus(): array
    {
        $extractorRegistry = GeneralUtility::makeInstance(ExtractorRegistry::class);

        $tikaClass = 'ApacheSolrForTypo3\\Tika\\Service\\Extractor\\TextExtractor';
        $tikaAvailable = class_exists($tikaClass);

        if ($tikaAvailable) {
            return [
                new Status(
                    'Apache Tika',
                    Status::OK,
                    'Apache Tika (via ext-tika) is available for file text extraction.',
                ),
            ];
        }

        return [
            new Status(
                'Text Extraction',
                Status::WARNING,
                'Apache Tika not available. Using metadata-only extraction. Install apache-solr-for-typo3/tika for full file content extraction.',
            ),
        ];
    }
}

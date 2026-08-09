<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use Maispace\MaiSearch\Extraction\TikaTextExtractor;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class TextExtractionStatus implements StatusProviderInterface
{
    public function getLabel(): string
    {
        return 'Text Extraction';
    }

    public function getStatus(): array
    {
        $tikaAvailable = GeneralUtility::makeInstance(TikaTextExtractor::class)->isAvailable();

        if ($tikaAvailable) {
            return [
                new Status(
                    'Apache Tika',
                    'Available',
                    'Apache Tika (via ext-tika) is available for file text extraction.',
                    ContextualFeedbackSeverity::OK,
                ),
            ];
        }

        return [
            new Status(
                'Text Extraction',
                'Metadata only',
                'Apache Tika not available. Using metadata-only extraction. Install apache-solr-for-typo3/tika for full file content extraction.',
                ContextualFeedbackSeverity::WARNING,
            ),
        ];
    }
}

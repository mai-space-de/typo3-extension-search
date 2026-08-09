<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Extraction;

use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Registry for text extractors. Returns the highest-priority extractor that supports a file.
 */
class ExtractorRegistry
{
    /**
     * @var TextExtractorInterface[]
     */
    private array $extractors = [];

    public function __construct()
    {
        $this->extractors = [
            GeneralUtility::makeInstance(NullTextExtractor::class),
        ];

        $tikaExtractor = GeneralUtility::makeInstance(TikaTextExtractor::class);
        if ($tikaExtractor->isAvailable()) {
            $this->extractors[] = $tikaExtractor;
        }

        usort($this->extractors, static fn(TextExtractorInterface $a, TextExtractorInterface $b): int => $b->getPriority() <=> $a->getPriority());
    }

    public function getExtractorForFile(FileInterface $file): TextExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($file)) {
                return $extractor;
            }
        }

        return $this->extractors[0] ?? GeneralUtility::makeInstance(NullTextExtractor::class);
    }
}

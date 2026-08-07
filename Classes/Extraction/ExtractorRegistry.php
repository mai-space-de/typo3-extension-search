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
        if ($tikaExtractor->supports($this->createDummyFile())) {
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

    private function createDummyFile(): FileInterface
    {
        return new class implements FileInterface {
            public function hasProperty(string $key): bool
            {
                return false;
            }

            public function getProperty(string $key): mixed
            {
                return null;
            }

            public function getSize(): int
            {
                return 0;
            }

            public function getSha1(): string
            {
                return '';
            }

            public function getName(): string
            {
                return 'dummy.txt';
            }

            public function getExtension(): string
            {
                return 'txt';
            }

            public function getMimeType(): string
            {
                return 'text/plain';
            }

            public function getStorage(): \TYPO3\CMS\Core\Resource\ResourceStorage
            {
                throw new \RuntimeException('Not implemented');
            }

            public function getIdentifier(): string
            {
                return '';
            }

            public function getContents(): string
            {
                return '';
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Extraction;

use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Text extractor using Apache Tika (via ext-tika if available).
 *
 * Only registered if apache-solr-for-typo3/tika classes exist (class_exists guard).
 * Delegates to whichever Tika mode (jar/server/solr) that extension is configured for.
 * mai_search does not reimplement Tika transport.
 */
class TikaTextExtractor implements TextExtractorInterface
{
    private const int MAX_FILE_SIZE = 52428800;

    private const array SUPPORTED_EXTENSIONS = [
        'doc', 'docx', 'epub', 'htm', 'html', 'msg', 'odf', 'odt',
        'pdf', 'ppt', 'pptx', 'rtf', 'sxw', 'txt', 'xls', 'xlsx', 'zip',
    ];

    public function extractText(FileInterface $file): string
    {
        if (!$this->supports($file)) {
            return '';
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return '';
        }

        $tikaExtractor = $this->getTikaExtractor();
        if ($tikaExtractor === null) {
            return '';
        }

        try {
            if (method_exists($tikaExtractor, 'extractText')) {
                return (string) $tikaExtractor->extractText($file);
            }

            if (method_exists($tikaExtractor, 'getText')) {
                return (string) $tikaExtractor->getText($file);
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    public function supports(FileInterface $file): bool
    {
        $extension = strtolower($file->getExtension());

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true)
            && $this->getTikaExtractor() !== null;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function isAvailable(): bool
    {
        return $this->getTikaExtractor() !== null;
    }

    private function getTikaExtractor(): ?object
    {
        $tikaClass = 'ApacheSolrForTypo3\\Tika\\Service\\Extractor\\TextExtractor';
        if (!class_exists($tikaClass)) {
            return null;
        }

        try {
            // Plain `new`, not GeneralUtility::makeInstance(): this is a foreign,
            // optionally-installed vendor class we don't own and don't want
            // XCLASS-overridable — makeInstance()'s class-string<T> generic can't
            // be satisfied for a class PHPStan has no reflection data for anyway.
            return new $tikaClass();
        } catch (\Throwable) {
            return null;
        }
    }
}

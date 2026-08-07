<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Extraction;

use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Extracts text content from files for search indexing.
 *
 * Implementations may use Apache Tika, metadata-only extraction, or other methods.
 * The interface is backend-agnostic — extractors don't know which search backend is active.
 */
interface TextExtractorInterface
{
    /**
     * Extract text content from a file.
     *
     * @param FileInterface $file The file to extract text from
     * @return string Extracted text (may be empty if extraction fails or file is unsupported)
     */
    public function extractText(FileInterface $file): string;

    /**
     * Check if this extractor supports the given file.
     *
     * @param FileInterface $file The file to check
     * @return bool True if this extractor can handle the file
     */
    public function supports(FileInterface $file): bool;

    /**
     * Get the priority of this extractor. Higher priority extractors are tried first.
     *
     * @return int Priority (default: 0)
     */
    public function getPriority(): int;
}

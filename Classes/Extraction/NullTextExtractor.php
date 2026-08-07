<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Extraction;

use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Default text extractor that returns metadata only (title, description).
 *
 * Always available, no external dependencies. Use this as a fallback when
 * no other extractor (e.g., Tika) is configured or available.
 */
class NullTextExtractor implements TextExtractorInterface
{
    public function extractText(FileInterface $file): string
    {
        $parts = [];

        if ($file->hasProperty('title')) {
            $title = (string) $file->getProperty('title');
            if ($title !== '') {
                $parts[] = $title;
            }
        }

        if ($file->hasProperty('description')) {
            $description = (string) $file->getProperty('description');
            if ($description !== '') {
                $parts[] = $description;
            }
        }

        return implode("\n", $parts);
    }

    public function supports(FileInterface $file): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Domain\Service\VectorEmbeddingInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bridges mai_translate's EmbeddingServiceInterface to mai_search's
 * VectorEmbeddingInterface at runtime, without a compile-time dependency.
 *
 * Uses class_exists() so the adapter is safe to load even when mai_translate
 * is not installed. Without mai_translate, embedText() returns an empty array
 * and indexing proceeds without vector generation.
 */
final class VectorEmbeddingAdapter implements VectorEmbeddingInterface
{
    private const string EMBEDDING_SERVICE_CLASS = 'Maispace\\MaiTranslate\\Service\\EmbeddingServiceInterface';

    private ?object $embeddingService = null;

    public function embedText(string $text): array
    {
        $service = $this->resolve();

        if ($service === null) {
            return [];
        }

        return $service->embedText($text);
    }

    public function getMaxInputTokens(): int
    {
        $service = $this->resolve();

        if ($service === null) {
            return 0;
        }

        return $service->getMaxInputTokens();
    }

    private function resolve(): ?object
    {
        if ($this->embeddingService !== null) {
            return $this->embeddingService;
        }

        $className = self::EMBEDDING_SERVICE_CLASS;

        if (!class_exists($className)) {
            return null;
        }

        try {
            /** @var object $instance */
            $instance = GeneralUtility::makeInstance($className);
            $this->embeddingService = $instance;
        } catch (\Throwable) {
            return null;
        }

        return $this->embeddingService;
    }
}

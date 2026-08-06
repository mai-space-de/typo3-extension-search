<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Support;

/**
 * Reads a dot-notation path (e.g. 'choices.0.delta.content') out of a decoded
 * JSON response. Shared by embedding and chat providers that parse REST/SSE/NDJSON
 * payloads with configurable response shapes.
 */
trait ExtractsResponsePath
{
    /**
     * protected, not private: CustomChatProvider/CustomEmbeddingProvider call
     * this directly even though the trait is `use`d by their Abstract* parent
     * — PHP scopes trait-private methods to the class that uses the trait, not
     * to its subclasses, so private here would break exactly that call site.
     */
    protected function extractByPath(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];

                continue;
            }

            return null;
        }

        return $data;
    }
}

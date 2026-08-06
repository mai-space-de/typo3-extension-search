<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Embedding;

use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Support\ExtractsResponsePath;
use TYPO3\CMS\Core\Http\RequestFactory;

abstract class AbstractEmbeddingProvider implements EmbeddingProviderInterface
{
    use ExtractsResponsePath;

    public function __construct(protected readonly RequestFactory $requestFactory) {}

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }
}

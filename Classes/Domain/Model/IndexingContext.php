<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

class IndexingContext
{
    /**
     * @param int<1, max> $batchSize
     * @param string|null $languageCode ISO 639-1 language code (e.g. 'de', 'en') for multi-language core selection
     * @param int|null $languageUid sys_language_uid matching $languageCode, for filtering records to index
     */
    public function __construct(
        public readonly string $core,
        public readonly int $batchSize = 100,
        public readonly int $offset = 0,
        public readonly ?string $languageCode = null,
        public readonly ?int $languageUid = null,
    ) {}
}

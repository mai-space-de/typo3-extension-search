<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

/**
 * Outcome of ProvisioningService::provision() — reported by both the
 * `mai-search:provision` CLI command and the backend module's "Provision"
 * button, so the two surfaces stay in sync automatically.
 */
readonly class ProvisioningResult
{
    /**
     * @param list<string> $provisioned Cores/indexes newly created or ensured, backend-specific
     */
    public function __construct(
        public string $backend,
        public array $provisioned,
        public ?string $message = null,
    ) {}
}

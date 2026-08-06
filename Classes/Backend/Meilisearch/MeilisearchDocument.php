<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

/**
 * Value object for a single Meilisearch document. Effectively immutable:
 * every mutation returns a new instance via withField().
 */
final class MeilisearchDocument
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(private array $fields = []) {}

    public function withField(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->fields[$name] = $value;

        return $clone;
    }

    public function getId(): string|int|null
    {
        return $this->fields['id'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}

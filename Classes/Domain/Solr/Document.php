<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

/**
 * Solr index document with a simple field bag.
 */
final class Document
{
    /**
     * @var array<string, mixed>
     */
    private array $fields = [];

    public function setField(string $name, mixed $value): void
    {
        $this->fields[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getField(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }
}

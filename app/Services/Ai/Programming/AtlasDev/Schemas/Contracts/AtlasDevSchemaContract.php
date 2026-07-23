<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Contracts;


interface AtlasDevSchemaContract
{
    public function schemaVersion(): string;

    public function toCanonicalArray(): array;

    /**
     * Provider-safe projection per contracts doc 3.4.
     *
     * Returns the canonical array verbatim when every field is provider-safe;
     * otherwise filtered fields are replaced by hash/ref tokens and the
     * substitution is recorded. Never silently drop a sensitive field.
     *
     * @return array<string, mixed>
     */
    public function toProviderSafeArray(): array;

    public function toJson(): string;

    public function hash(): string;

    public function isProviderSafe(): bool;
}

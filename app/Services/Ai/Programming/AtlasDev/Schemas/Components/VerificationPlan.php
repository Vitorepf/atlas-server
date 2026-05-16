<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class VerificationPlan implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.verification_plan.v1';

    /**
     * @param  list<string>  $commands
     */
    public function __construct(
        public readonly string $profile,
        public readonly array $commands,
        public readonly ?string $noTestReason = null,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            profile: AtlasDevSchemaArray::string($payload, 'profile'),
            commands: AtlasDevSchemaArray::stringList($payload, 'commands'),
            noTestReason: AtlasDevSchemaArray::nullableString($payload, 'no_test_reason'),
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'commands' => array_values($this->commands),
            'no_test_reason' => $this->noTestReason,
            'profile' => $this->profile,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }
}

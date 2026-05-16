<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class ContextBudget implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.context_budget.v1';

    public function __construct(
        public readonly int $maxChars,
        public readonly int $maxDocs,
        public readonly int $maxCandidateFiles,
        public readonly int $maxPlanSteps,
        public readonly int $maxProviderCalls,
        public readonly int $maxRepairAttempts,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            maxChars: AtlasDevSchemaArray::int($payload, 'max_chars'),
            maxDocs: AtlasDevSchemaArray::int($payload, 'max_docs'),
            maxCandidateFiles: AtlasDevSchemaArray::int($payload, 'max_candidate_files'),
            maxPlanSteps: AtlasDevSchemaArray::int($payload, 'max_plan_steps'),
            maxProviderCalls: AtlasDevSchemaArray::int($payload, 'max_provider_calls'),
            maxRepairAttempts: AtlasDevSchemaArray::int($payload, 'max_repair_attempts'),
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
            'max_candidate_files' => $this->maxCandidateFiles,
            'max_chars' => $this->maxChars,
            'max_docs' => $this->maxDocs,
            'max_plan_steps' => $this->maxPlanSteps,
            'max_provider_calls' => $this->maxProviderCalls,
            'max_repair_attempts' => $this->maxRepairAttempts,
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

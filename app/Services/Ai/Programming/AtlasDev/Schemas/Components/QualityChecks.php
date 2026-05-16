<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class QualityChecks implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.quality_checks.v1';

    public function __construct(
        public readonly bool $noMissingRequiredSections,
        public readonly bool $noUnboundedScope,
        public readonly bool $noHiddenBenchmarkInstruction,
        public readonly bool $noConflictingFileRules,
        public readonly bool $noForgeOrCouncilLeakage,
        public readonly bool $providerSafe,
    ) {}

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'no_conflicting_file_rules' => $this->noConflictingFileRules,
            'no_forge_or_council_leakage' => $this->noForgeOrCouncilLeakage,
            'no_hidden_benchmark_instruction' => $this->noHiddenBenchmarkInstruction,
            'no_missing_required_sections' => $this->noMissingRequiredSections,
            'no_unbounded_scope' => $this->noUnboundedScope,
            'provider_safe' => $this->providerSafe,
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

    public function allPassed(): bool
    {
        return $this->noMissingRequiredSections
            && $this->noUnboundedScope
            && $this->noHiddenBenchmarkInstruction
            && $this->noConflictingFileRules
            && $this->noForgeOrCouncilLeakage
            && $this->providerSafe;
    }

    /**
     * @return list<string>
     */
    public function failedChecks(): array
    {
        $failed = [];
        if (! $this->noMissingRequiredSections) {
            $failed[] = 'no_missing_required_sections';
        }
        if (! $this->noUnboundedScope) {
            $failed[] = 'no_unbounded_scope';
        }
        if (! $this->noHiddenBenchmarkInstruction) {
            $failed[] = 'no_hidden_benchmark_instruction';
        }
        if (! $this->noConflictingFileRules) {
            $failed[] = 'no_conflicting_file_rules';
        }
        if (! $this->noForgeOrCouncilLeakage) {
            $failed[] = 'no_forge_or_council_leakage';
        }
        if (! $this->providerSafe) {
            $failed[] = 'provider_safe';
        }

        return $failed;
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            noMissingRequiredSections: AtlasDevSchemaArray::bool($payload, 'no_missing_required_sections'),
            noUnboundedScope: AtlasDevSchemaArray::bool($payload, 'no_unbounded_scope'),
            noHiddenBenchmarkInstruction: AtlasDevSchemaArray::bool($payload, 'no_hidden_benchmark_instruction'),
            noConflictingFileRules: AtlasDevSchemaArray::bool($payload, 'no_conflicting_file_rules'),
            noForgeOrCouncilLeakage: AtlasDevSchemaArray::bool($payload, 'no_forge_or_council_leakage'),
            providerSafe: AtlasDevSchemaArray::bool($payload, 'provider_safe'),
        );
    }

    public static function allPassing(): self
    {
        return new self(
            noMissingRequiredSections: true,
            noUnboundedScope: true,
            noHiddenBenchmarkInstruction: true,
            noConflictingFileRules: true,
            noForgeOrCouncilLeakage: true,
            providerSafe: true,
        );
    }
}

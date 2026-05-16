<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\ProviderSafeRedactor;

final class ContextRetrievalPlan implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.context_retrieval_plan.v1';

    private const HASH_FIELD = 'plan_hash';

    /**
     * @param  list<string>  $selectedTiers  subset of [core, code_intelligence, sdd, interface, forge, obras]
     * @param  list<string>  $requiredSources  refs (doc anchors, code symbols, memory ids) that must be present
     * @param  list<string>  $optionalSources
     * @param  list<string>  $missingSources  sources requested but unavailable; bloqueia se intersect com required
     * @param  array<string, mixed>  $truncationPolicy  e.g. ['policy' => 'drop_optional', 'on_overflow' => 'truncate_optional', 'reasons' => []]
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $selectedTiers,
        public readonly int $budgetChars,
        public readonly array $requiredSources,
        public readonly array $optionalSources,
        public readonly array $missingSources,
        public readonly array $truncationPolicy,
        public readonly bool $providerSafe,
        public readonly string $planHash,
    ) {}

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'budget_chars' => $this->budgetChars,
            'missing_sources' => array_values($this->missingSources),
            'optional_sources' => array_values($this->optionalSources),
            'plan_hash' => $this->planHash,
            'provider_safe' => $this->providerSafe,
            'required_sources' => array_values($this->requiredSources),
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'selected_tiers' => array_values($this->selectedTiers),
            'truncation_policy' => $this->truncationPolicy,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        $canonical = $this->toCanonicalArray();
        if ($this->providerSafe) {
            return $canonical;
        }

        $canonical['required_sources'] = ProviderSafeRedactor::redactStringList(
            $this->requiredSources,
            'context_retrieval_plan.required_sources',
        );
        $canonical['optional_sources'] = ProviderSafeRedactor::redactStringList(
            $this->optionalSources,
            'context_retrieval_plan.optional_sources',
        );
        $canonical['missing_sources'] = ProviderSafeRedactor::redactStringList(
            $this->missingSources,
            'context_retrieval_plan.missing_sources',
        );

        return CanonicalJson::canonicalize($canonical);
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            selectedTiers: array_values((array) ($payload['selected_tiers'] ?? [])),
            budgetChars: (int) $payload['budget_chars'],
            requiredSources: array_values((array) ($payload['required_sources'] ?? [])),
            optionalSources: array_values((array) ($payload['optional_sources'] ?? [])),
            missingSources: array_values((array) ($payload['missing_sources'] ?? [])),
            truncationPolicy: (array) ($payload['truncation_policy'] ?? []),
            providerSafe: (bool) ($payload['provider_safe'] ?? true),
            planHash: (string) ($payload['plan_hash'] ?? ''),
        );
    }
}

<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ExternalProviderCapabilityRegistry
{
    public const STATUS_RESEARCH_PREVIEW = 'research_preview';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_FUTURE = 'future';

    public const STATUS_DEPRECATED = 'deprecated';

    public const AUTHORITY_PROPOSAL_ONLY = 'proposal_only_atlas_remains_authority';

    public const AUTHORITY_READ_ONLY = 'read_only_capability_signal';

    /**
     * @param  array{provider?:string|null,status?:string|null,capability_type?:string|null,domain?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function summary(array $filters = []): array
    {
        $capabilities = $this->capabilities($filters)->values();

        return [
            'schema_version' => 'atlas.external_provider_capability_registry.v1',
            'status' => 'ok',
            'mode' => 'read_only_registry',
            'authority' => 'capability_inventory_only_no_runtime_execution',
            'capability_count' => $capabilities->count(),
            'filters' => [
                'provider' => $this->cleanString($filters['provider'] ?? null),
                'status' => $this->cleanString($filters['status'] ?? null),
                'capability_type' => $this->cleanString($filters['capability_type'] ?? null),
                'domain' => $this->cleanString($filters['domain'] ?? null),
            ],
            'counts' => [
                'by_provider' => $capabilities->countBy(fn (ExternalProviderCapability $capability): string => $capability->provider)->all(),
                'by_status' => $capabilities->countBy(fn (ExternalProviderCapability $capability): string => $capability->status)->all(),
                'by_type' => $capabilities->countBy(fn (ExternalProviderCapability $capability): string => $capability->capabilityType)->all(),
            ],
            'capabilities' => $capabilities
                ->map(fn (ExternalProviderCapability $capability): array => $this->capabilityPayload($capability))
                ->all(),
            'guardrails' => $this->guardrails(),
        ];
    }

    /**
     * @param  array{provider?:string|null,status?:string|null,capability_type?:string|null,domain?:string|null}  $filters
     * @return Collection<int,ExternalProviderCapability>
     */
    public function capabilities(array $filters = []): Collection
    {
        $provider = $this->normalize($filters['provider'] ?? null);
        $status = $this->normalize($filters['status'] ?? null);
        $type = $this->normalize($filters['capability_type'] ?? null);
        $domain = $this->normalize($filters['domain'] ?? null);

        return collect($this->definitions())
            ->map(fn (array $definition): ExternalProviderCapability => new ExternalProviderCapability(...$definition))
            ->filter(fn (ExternalProviderCapability $capability): bool => $provider === null || $capability->provider === $provider)
            ->filter(fn (ExternalProviderCapability $capability): bool => $status === null || $capability->status === $status)
            ->filter(fn (ExternalProviderCapability $capability): bool => $type === null || $capability->capabilityType === $type)
            ->filter(fn (ExternalProviderCapability $capability): bool => $domain === null || in_array($domain, $capability->domains, true))
            ->values();
    }

    public function findById(string $id): ?ExternalProviderCapability
    {
        $normalized = $this->normalize($id);

        return $this->capabilities()
            ->first(fn (ExternalProviderCapability $capability): bool => $capability->id === $normalized);
    }

    /**
     * @return array<string,mixed>
     */
    public function adoptionCandidate(string $id): array
    {
        $capability = $this->findById($id);

        if ($capability === null) {
            return [
                'schema_version' => 'atlas.external_provider_capability_adoption_candidate.v1',
                'status' => 'not_found',
                'id' => $id,
                'review_required' => true,
                'recommended_action' => 'register_capability_before_review',
                'changes_routing' => false,
                'writes_policy' => false,
            ];
        }

        return [
            'schema_version' => 'atlas.external_provider_capability_adoption_candidate.v1',
            'status' => 'candidate',
            'capability' => $this->capabilityPayload($capability),
            'review_required' => true,
            'recommended_action' => $this->recommendedAction($capability),
            'promotion_requires' => $this->promotionRequirements($capability),
            'changes_routing' => false,
            'writes_policy' => false,
            'writes_memory_core' => false,
            'writes_provider_projection' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function capabilityPayload(ExternalProviderCapability $capability): array
    {
        return array_merge($capability->toArray(), [
            'adoption' => [
                'recommended_action' => $this->recommendedAction($capability),
                'promotion_requires' => $this->promotionRequirements($capability),
                'review_required' => true,
            ],
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function promotionRequirements(ExternalProviderCapability $capability): array
    {
        $requirements = [
            'provider_release_review',
            'human_review',
            'owner_doc_update',
            'no_direct_runtime_execution',
        ];

        if (in_array($capability->status, [self::STATUS_RESEARCH_PREVIEW, self::STATUS_FUTURE], true)) {
            $requirements[] = 'availability_confirmation';
        }

        if (in_array('memory', $capability->domains, true) || $capability->capabilityType === 'provider_dream_memory') {
            $requirements[] = 'memory_diff_review';
            $requirements[] = 'decision_receipt_before_promotion';
        }

        if (in_array($capability->capabilityType, ['vertical_agent', 'coding_agent', 'realtime'], true)) {
            $requirements[] = 'rivals_or_domain_benchmark';
        }

        return array_values(array_unique($requirements));
    }

    private function recommendedAction(ExternalProviderCapability $capability): string
    {
        if ($capability->status === self::STATUS_DEPRECATED) {
            return 'archive_or_remove_candidate';
        }

        if ($capability->status === self::STATUS_AVAILABLE) {
            return 'benchmark_and_absorb_as_adapter';
        }

        if ($capability->capabilityType === 'provider_dream_memory') {
            return 'wait_for_operational_access_then_create_proposal_only_adapter';
        }

        return 'track_until_available';
    }

    /**
     * @return array<string,mixed>
     */
    private function guardrails(): array
    {
        return [
            'network_fetching_enabled' => false,
            'runtime_execution_enabled' => false,
            'writes_policy' => false,
            'changes_routing' => false,
            'writes_memory_core' => false,
            'writes_provider_projection' => false,
            'requires_provider_release_envelope_before_adoption' => true,
            'requires_human_review_before_promotion' => true,
        ];
    }

    private function normalize(mixed $value): ?string
    {
        $value = Str::of((string) $value)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();

        return $value !== '' ? $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'id' => 'anthropic_claude_dreams',
                'provider' => 'anthropic',
                'name' => 'Claude Managed Agents Dreams',
                'capabilityType' => 'provider_dream_memory',
                'status' => self::STATUS_RESEARCH_PREVIEW,
                'authority' => self::AUTHORITY_PROPOSAL_ONLY,
                'domains' => ['memory', 'self_improvement'],
                'surfaces' => ['managed_agents', 'api'],
                'runtimes' => ['provider_managed_agent'],
                'requiredGates' => ['privacy_redaction', 'memory_diff_review', 'human_review', 'decision_receipt'],
                'sourceRefs' => ['docs/ap/AP-171-provider-dream-memory-layer-contract.md'],
            ],
            [
                'id' => 'anthropic_finance_agents',
                'provider' => 'anthropic',
                'name' => 'Anthropic Finance Agents',
                'capabilityType' => 'vertical_agent',
                'status' => self::STATUS_AVAILABLE,
                'authority' => self::AUTHORITY_READ_ONLY,
                'domains' => ['finance'],
                'surfaces' => ['office', 'managed_agents', 'api'],
                'runtimes' => ['provider_managed_agent', 'connector_registry'],
                'requiredGates' => ['provider_release_review', 'rivals_finance', 'human_review', 'ap99_performance'],
                'sourceRefs' => ['docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md'],
            ],
            [
                'id' => 'openai_realtime_agents',
                'provider' => 'openai',
                'name' => 'OpenAI Realtime Agents',
                'capabilityType' => 'realtime',
                'status' => self::STATUS_FUTURE,
                'authority' => self::AUTHORITY_READ_ONLY,
                'domains' => ['voice', 'mobile', 'programming'],
                'surfaces' => ['mobile', 'voice_realtime', 'api'],
                'runtimes' => ['python_ai_data', 'swift_native_edge'],
                'requiredGates' => ['voice_privacy_gate', 'latency_slo', 'human_review'],
                'sourceRefs' => ['docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md'],
            ],
            [
                'id' => 'google_gemini_long_context',
                'provider' => 'google',
                'name' => 'Gemini Long Context',
                'capabilityType' => 'model_capability',
                'status' => self::STATUS_AVAILABLE,
                'authority' => self::AUTHORITY_READ_ONLY,
                'domains' => ['research', 'programming', 'memory'],
                'surfaces' => ['cli', 'api'],
                'runtimes' => ['provider_driver'],
                'requiredGates' => ['ap99_performance', 'privacy_redaction', 'context_budget_review'],
                'sourceRefs' => ['docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md'],
            ],
            [
                'id' => 'cursor_coding_agent_modes',
                'provider' => 'cursor',
                'name' => 'Cursor Coding Agent Modes',
                'capabilityType' => 'coding_agent',
                'status' => self::STATUS_AVAILABLE,
                'authority' => self::AUTHORITY_READ_ONLY,
                'domains' => ['programming'],
                'surfaces' => ['ide', 'desktop'],
                'runtimes' => ['external_tool_runtime'],
                'requiredGates' => ['rivals_programming', 'workspace_sandbox', 'human_review'],
                'sourceRefs' => ['docs/ap/AP-172-provider-release-source-watchlist.md'],
            ],
        ];
    }
}

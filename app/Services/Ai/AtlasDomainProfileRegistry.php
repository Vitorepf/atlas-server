<?php

namespace App\Services\Ai;

use App\Models\AiDomainProfile;
use App\Models\AiFlowProfile;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasDomainProfileRegistry
{
    /**
     * @return array{schema_version:int,source:string,domains:array<int,array<string,mixed>>,flows:array<int,array<string,mixed>>}
     */
    public function catalog(): array
    {
        $database = $this->catalogFromDatabase();

        if ($database !== null) {
            return $database;
        }

        return [
            'schema_version' => 1,
            'source' => 'static_fallback',
            'domains' => array_values($this->staticDomains()),
            'flows' => array_values($this->staticFlows()),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function resolve(string $profileId, array $options = []): array
    {
        $profileId = $this->normalizeProfileId($profileId, $options);
        $database = $this->resolveFromDatabase($profileId);

        if ($database !== null) {
            return $database;
        }

        return $this->resolveFromStaticProfiles($profileId);
    }

    /**
     * @return array{schema_version:int,source:string,domains:array<int,array<string,mixed>>,flows:array<int,array<string,mixed>>}|null
     */
    private function catalogFromDatabase(): ?array
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return null;
        }

        try {
            return [
                'schema_version' => 1,
                'source' => 'database',
                'domains' => AiDomainProfile::query()
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AiDomainProfile $profile): array => $profile->toArray())
                    ->values()
                    ->all(),
                'flows' => AiFlowProfile::query()
                    ->where('status', 'active')
                    ->orderBy('domain_id')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AiFlowProfile $profile): array => $profile->toArray())
                    ->values()
                    ->all(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveFromDatabase(string $profileId): ?array
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return null;
        }

        try {
            $flow = AiFlowProfile::query()
                ->where('id', $profileId)
                ->where('status', 'active')
                ->first();

            if (! $flow) {
                return null;
            }

            $domain = AiDomainProfile::query()
                ->where('id', $flow->domain_id)
                ->where('status', 'active')
                ->first();

            return $this->receipt(
                profileId: $profileId,
                domainId: (string) ($flow->domain_id ?: $this->domainFromProfileId($profileId)),
                flowId: (string) $flow->id,
                domain: $domain?->toArray() ?? $this->domainFallback($this->domainFromProfileId($profileId)),
                flow: $flow->toArray(),
                source: 'database',
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveFromStaticProfiles(string $profileId): array
    {
        $flows = $this->staticFlows();
        $flow = $flows[$profileId] ?? $this->flowFallback($profileId);
        $domainId = (string) ($flow['domain_id'] ?? $this->domainFromProfileId($profileId));
        $domain = $this->staticDomains()[$domainId] ?? $this->domainFallback($domainId);

        return $this->receipt(
            profileId: $profileId,
            domainId: $domainId,
            flowId: (string) ($flow['id'] ?? $profileId),
            domain: $domain,
            flow: $flow,
            source: 'static_fallback',
        );
    }

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<string,mixed>  $flow
     * @return array<string,mixed>
     */
    private function receipt(
        string $profileId,
        string $domainId,
        string $flowId,
        array $domain,
        array $flow,
        string $source,
    ): array {
        return [
            'schema_version' => 1,
            'profile_id' => $profileId,
            'domain_id' => $domainId,
            'flow_id' => $flowId,
            'domain_profile' => $domain,
            'flow_profile' => $flow,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function normalizeProfileId(string $profileId, array $options): string
    {
        $profileId = strtolower(trim($profileId));

        if ($profileId !== '') {
            return $profileId;
        }

        $mode = strtolower(trim((string) ($options['mode'] ?? 'general')));
        $task = strtolower(trim((string) ($options['task'] ?? 'answer')));

        return ($mode !== '' ? $mode : 'general').'.'.($task !== '' ? $task : 'answer');
    }

    private function domainFromProfileId(string $profileId): string
    {
        $domain = str($profileId)->before('.')->toString();

        return $domain !== '' ? $domain : 'general';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function staticDomains(): array
    {
        return [
            'general' => [
                'id' => 'general',
                'label' => 'General',
                'status' => 'active',
                'default_flow' => 'general.answer',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime_family' => 'conversation',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'research' => [
                'id' => 'research',
                'label' => 'Research',
                'status' => 'active',
                'default_flow' => 'research.quick',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime_family' => 'research',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'programming' => [
                'id' => 'programming',
                'label' => 'Programming',
                'status' => 'active',
                'default_flow' => 'programming.dev',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime_family' => 'engineering',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'finance' => [
                'id' => 'finance',
                'label' => 'Finance',
                'status' => 'active',
                'default_flow' => 'finance.research',
                'orchestrator' => 'AtlasFinanceOrchestrator',
                'runtime_family' => 'finance',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'personal_development' => [
                'id' => 'personal_development',
                'label' => 'Personal Development',
                'status' => 'active',
                'default_flow' => 'personal_development.reflect',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime_family' => 'personal_development',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'background' => [
                'id' => 'background',
                'label' => 'Background',
                'status' => 'active',
                'default_flow' => 'background.safe',
                'orchestrator' => 'BackgroundSafetyOrchestrator',
                'runtime_family' => 'background',
                'autonomy_default' => 'low',
                'background_allowed' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function staticFlows(): array
    {
        return [
            'general.answer' => [
                'id' => 'general.answer',
                'domain_id' => 'general',
                'label' => 'Answer',
                'status' => 'active',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime' => 'StandardAiResponse',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'research.quick' => [
                'id' => 'research.quick',
                'domain_id' => 'research',
                'label' => 'Quick Research',
                'status' => 'active',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime' => 'ResearchRuntime',
                'autonomy' => 'medium',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'research.super' => [
                'id' => 'research.super',
                'domain_id' => 'research',
                'label' => 'Super Research',
                'status' => 'active',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime' => 'ResearchRuntime',
                'autonomy' => 'high',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'programming.dev' => [
                'id' => 'programming.dev',
                'domain_id' => 'programming',
                'label' => 'Dev',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'ProviderExecution',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'skill_policy' => [
                    'preset' => 'domain',
                    'required_bundles' => ['dev-quality-gate'],
                    'require_skill_trace' => true,
                ],
                'execution_policy' => ['executor_preference' => 'simple_provider_execution'],
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'programming.forge' => [
                'id' => 'programming.forge',
                'domain_id' => 'programming',
                'label' => 'Forge',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'EngineeringHarness',
                'autonomy' => 'high',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'skill_policy' => [
                    'preset' => 'domain',
                    'required_bundles' => ['engineering-blueprint', 'dev-quality-gate', 'code-reviewer'],
                    'require_skill_trace' => true,
                ],
                'execution_policy' => ['executor_preference' => 'engineering_harness'],
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'finance.research' => [
                'id' => 'finance.research',
                'domain_id' => 'finance',
                'label' => 'Finance Research',
                'status' => 'active',
                'orchestrator' => 'AtlasFinanceOrchestrator',
                'runtime' => 'FinanceRuntime',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'personal_development.reflect' => [
                'id' => 'personal_development.reflect',
                'domain_id' => 'personal_development',
                'label' => 'Reflect',
                'status' => 'active',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime' => 'PersonalDevelopmentRuntime',
                'autonomy' => 'medium',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'background.safe' => [
                'id' => 'background.safe',
                'domain_id' => 'background',
                'label' => 'Safe Background',
                'status' => 'active',
                'orchestrator' => 'BackgroundSafetyOrchestrator',
                'runtime' => 'StandardAiResponse',
                'autonomy' => 'low',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'metadata' => ['registry' => 'static_fallback'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainFallback(string $domainId): array
    {
        return [
            'id' => $domainId,
            'label' => str($domainId)->replace('_', ' ')->title()->toString(),
            'status' => 'active',
            'default_flow' => $domainId.'.default',
            'orchestrator' => 'StandardResponseOrchestrator',
            'runtime_family' => 'conversation',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'metadata' => ['registry' => 'generated_fallback'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowFallback(string $profileId): array
    {
        return [
            'id' => $profileId,
            'domain_id' => $this->domainFromProfileId($profileId),
            'label' => str($profileId)->after('.')->replace('_', ' ')->title()->toString(),
            'status' => 'active',
            'orchestrator' => 'StandardResponseOrchestrator',
            'runtime' => 'StandardAiResponse',
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'metadata' => ['registry' => 'generated_fallback'],
        ];
    }
}

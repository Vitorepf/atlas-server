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
                'description' => 'Code, debugging, refactor, QA, security, and engineering implementation.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => [
                    'required' => ['context_pack', 'tool_permission_contract', 'final_quality_summary'],
                    'release_requires' => ['tests_or_reason', 'diff_scope', 'open_brain_audit'],
                ],
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => [
                        'cli:atlas dev',
                        'cli:atlas forge',
                        'cli:atlas fix',
                        'cli:atlas continue',
                        'cli:atlas chat --mode=dev',
                        'cli:atlas chat --mode=review',
                        'cli:atlas chat --mode=debug',
                        'api:ai/jobs',
                        'app:programming',
                        'mcp:open_brain',
                    ],
                    'surface_policy' => [
                        'all_surfaces_must_call_programming_orchestrator' => true,
                        'interactive_and_prompt_dev_share_contract' => true,
                        'fix_and_continue_are_programming_flows_not_separate_products' => true,
                    ],
                    'learning_policy' => [
                        'nightly_self_improvement_reads_programming_evidence' => true,
                        'promote_repeated_repairs_to_memory' => true,
                        'detect_missing_surface_capabilities' => true,
                    ],
                ],
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
            'marketing' => [
                'id' => 'marketing',
                'label' => 'Marketing',
                'status' => 'active',
                'default_flow' => 'marketing.campaign',
                'orchestrator' => 'AtlasMarketingOrchestrator',
                'runtime_family' => 'marketing',
                'description' => 'Strategy, research, positioning, campaign planning, creative production, channel assets, experiments, analytics, and brand governance.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'context_policy' => $this->marketingContextPolicy(),
                'memory_policy' => $this->marketingMemoryPolicy(),
                'gate_policy' => $this->marketingDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => [
                        'cli:atlas ai --domain=marketing',
                        'api:ai/domains',
                        'app:marketing',
                        'app:atlas engineering',
                        'mcp:open_brain',
                    ],
                    'surface_policy' => [
                        'generation_is_draft_until_operator_approval' => true,
                        'external_publishing_requires_explicit_surface' => true,
                        'brand_and_claim_gates_are_required' => true,
                    ],
                    'learning_policy' => [
                        'campaign_results_feed_memory' => true,
                        'accepted_brand_feedback_updates_projection' => true,
                        'failed_experiments_create_learning_delta' => true,
                    ],
                ],
            ],
            'self_improvement' => [
                'id' => 'self_improvement',
                'label' => 'Self Improvement',
                'status' => 'active',
                'default_flow' => 'self_improvement.nightly_review',
                'orchestrator' => 'AtlasSelfImprovementOrchestrator',
                'runtime_family' => 'self_improvement',
                'description' => 'Scheduled Atlas self-review, gap detection, proposals, benchmark review, and safe process evolution.',
                'autonomy_default' => 'low',
                'background_allowed' => true,
                'context_policy' => $this->selfImprovementContextPolicy(),
                'memory_policy' => $this->selfImprovementMemoryPolicy(),
                'gate_policy' => $this->selfImprovementGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => [
                        'scheduler:daily-02:00',
                        'cli:atlas:ai:self-improve',
                        'api:ai/domains',
                        'api:mobile/inbox',
                        'app:atlas engineering',
                    ],
                    'surface_policy' => [
                        'nightly_runs_are_review_first' => true,
                        'medium_plus_changes_require_operator_approval' => true,
                        'proposals_link_back_to_evidence_ledger' => true,
                    ],
                    'learning_policy' => [
                        'read_all_domain_scorecards' => true,
                        'open_initiatives_for_missing_contracts' => true,
                        'promote_accepted_improvements_to_memory' => true,
                    ],
                ],
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
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(
                    ['context_pack', 'change_summary', 'quality_gate_summary'],
                    'simple_provider_execution',
                ),
                'metadata' => $this->programmingFlowMetadata(),
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
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(
                    ['engineering_harness_run', 'test_evidence', 'artifact_bundle', 'release_gate_summary'],
                    'engineering_harness',
                ),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.repair' => [
                'id' => 'programming.repair',
                'domain_id' => 'programming',
                'label' => 'Repair',
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
                'execution_policy' => [
                    'executor_preference' => 'dev_repair_executor',
                    'quality_required' => true,
                    'auto_test' => true,
                    'max_iterations' => 3,
                ],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['dev_quality_gate', 'repair_evidence'], 'dev_repair_executor'),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.review' => [
                'id' => 'programming.review',
                'domain_id' => 'programming',
                'label' => 'Review',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'ProviderExecution',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'skill_policy' => [
                    'preset' => 'domain',
                    'required_bundles' => ['code-reviewer'],
                    'require_skill_trace' => true,
                ],
                'tool_policy' => [
                    'mode' => 'read_only',
                    'workspace_write' => false,
                ],
                'execution_policy' => ['executor_preference' => 'simple_provider_execution'],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['review_findings', 'risk_summary'], 'simple_provider_execution'),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.refactor' => [
                'id' => 'programming.refactor',
                'domain_id' => 'programming',
                'label' => 'Refactor',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'ProviderExecution',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'execution_policy' => [
                    'executor_preference' => 'dev_repair_executor',
                    'quality_required' => true,
                    'auto_test' => true,
                ],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['change_scope', 'quality_evidence'], 'dev_repair_executor'),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.qa' => [
                'id' => 'programming.qa',
                'domain_id' => 'programming',
                'label' => 'QA',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'EngineeringHarness',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'execution_policy' => [
                    'executor_preference' => 'engineering_harness',
                    'quality_required' => true,
                    'harness_required' => true,
                ],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['test_evidence', 'regression_risk'], 'engineering_harness'),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.security' => [
                'id' => 'programming.security',
                'domain_id' => 'programming',
                'label' => 'Security',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'EngineeringHarness',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'execution_policy' => [
                    'executor_preference' => 'engineering_harness',
                    'quality_required' => true,
                    'harness_required' => true,
                ],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['security_evidence', 'risk_acceptance'], 'engineering_harness'),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.database' => [
                'id' => 'programming.database',
                'domain_id' => 'programming',
                'label' => 'Database',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'EngineeringHarness',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'execution_policy' => [
                    'executor_preference' => 'engineering_harness',
                    'quality_required' => true,
                    'harness_required' => true,
                ],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['migration_safety', 'rollback_plan'], 'engineering_harness'),
                'metadata' => $this->programmingFlowMetadata(),
            ],
            'programming.visual' => [
                'id' => 'programming.visual',
                'domain_id' => 'programming',
                'label' => 'Visual',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'EngineeringHarness',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'execution_policy' => [
                    'executor_preference' => 'engineering_harness',
                    'quality_required' => true,
                    'harness_required' => true,
                ],
                'context_policy' => $this->programmingContextPolicy(),
                'memory_policy' => $this->programmingMemoryPolicy(),
                'gate_policy' => $this->programmingFlowGatePolicy(['visual_evidence', 'responsive_check'], 'engineering_harness'),
                'metadata' => $this->programmingFlowMetadata(),
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
            ...$this->marketingStaticFlows(),
            'self_improvement.nightly_review' => [
                'id' => 'self_improvement.nightly_review',
                'domain_id' => 'self_improvement',
                'label' => 'Nightly Review',
                'status' => 'active',
                'orchestrator' => 'AtlasSelfImprovementOrchestrator',
                'runtime' => 'SelfImprovementRuntime',
                'autonomy' => 'low',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'gate_policy' => $this->selfImprovementGatePolicy(),
                'execution_policy' => ['executor_preference' => 'scheduled_self_improvement'],
                'context_policy' => $this->selfImprovementContextPolicy(),
                'memory_policy' => $this->selfImprovementMemoryPolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['scheduler', 'cli', 'api', 'app'],
                    'learning' => [
                        'record_findings' => true,
                        'emit_safe_review_proposals' => true,
                        'feed_domain_onboarding' => true,
                    ],
                ],
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

    /**
     * @return array<string,mixed>
     */
    private function programmingContextPolicy(): array
    {
        return [
            'preset' => 'programming_open_brain',
            'require_context_pack' => true,
            'require_open_brain' => 'auto',
            'sources' => [
                'workspace_code_intelligence',
                'engineering_knowledge_base',
                'recent_changes',
                'tool_evidence',
                'atlas_memory_registry',
            ],
            'budget' => [
                'max_prompt_tokens' => 18000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingMemoryPolicy(): array
    {
        return [
            'projection' => 'programming',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_memory_delta', 'harness_evidence', 'quality_gate', 'repair_result'],
                'requires_review_for' => ['architecture_rule', 'security_rule', 'cross_project_preference'],
                'quality_score_required' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $required
     * @return array<string,mixed>
     */
    private function programmingFlowGatePolicy(array $required, string $executor): array
    {
        return [
            'required' => $required,
            'executor' => $executor,
            'requires_final_summary' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingFlowMetadata(): array
    {
        return [
            'registry' => 'static_fallback',
            'surfaces' => ['cli', 'api', 'app', 'mcp'],
            'learning' => [
                'record_evidence' => true,
                'promote_useful_patterns' => true,
                'feed_self_improvement' => true,
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function marketingStaticFlows(): array
    {
        return [
            'marketing.strategy' => $this->marketingFlow('marketing.strategy', 'Strategy', 'MarketingStrategyRuntime', 'medium', ['strategy_brief', 'business_goal', 'positioning_hypothesis']),
            'marketing.research' => $this->marketingFlow('marketing.research', 'Research', 'MarketingResearchRuntime', 'medium', ['source_pack', 'competitor_notes', 'audience_insights']),
            'marketing.positioning' => $this->marketingFlow('marketing.positioning', 'Positioning', 'MarketingStrategyRuntime', 'medium', ['positioning_statement', 'differentiation', 'proof_points']),
            'marketing.campaign' => $this->marketingFlow('marketing.campaign', 'Campaign', 'MarketingRuntime', 'medium', ['creative_brief', 'claim_substantiation', 'measurement_plan']),
            'marketing.creative' => $this->marketingFlow('marketing.creative', 'Creative', 'MarketingCreativeRuntime', 'medium', ['creative_variants', 'brand_alignment', 'creative_diversity']),
            'marketing.copywriting' => $this->marketingFlow('marketing.copywriting', 'Copywriting', 'MarketingCopyRuntime', 'medium', ['copy_variants', 'clarity_score', 'claim_substantiation']),
            'marketing.media_plan' => $this->marketingFlow('marketing.media_plan', 'Media Plan', 'MarketingMediaRuntime', 'medium', ['channel_fit', 'budget_rationale', 'measurement_plan']),
            'marketing.landing_page' => $this->marketingFlow('marketing.landing_page', 'Landing Page', 'MarketingAssetRuntime', 'medium', ['offer_clarity', 'conversion_flow', 'claim_substantiation']),
            'marketing.email' => $this->marketingFlow('marketing.email', 'Email', 'MarketingAssetRuntime', 'medium', ['message_match', 'deliverability_review', 'measurement_plan']),
            'marketing.social' => $this->marketingFlow('marketing.social', 'Social', 'MarketingAssetRuntime', 'medium', ['channel_fit', 'creative_variants', 'brand_alignment']),
            'marketing.video_script' => $this->marketingFlow('marketing.video_script', 'Video Script', 'MarketingCreativeRuntime', 'medium', ['hook_strength', 'script_structure', 'claim_substantiation']),
            'marketing.ab_test' => $this->marketingFlow('marketing.ab_test', 'A/B Test', 'MarketingExperimentRuntime', 'medium', ['hypothesis', 'variant_matrix', 'success_metric']),
            'marketing.analytics' => $this->marketingFlow('marketing.analytics', 'Analytics', 'MarketingAnalyticsRuntime', 'medium', ['metrics_snapshot', 'insight_summary', 'next_experiment']),
            'marketing.brand_review' => $this->marketingFlow('marketing.brand_review', 'Brand Review', 'MarketingReviewRuntime', 'low', ['brand_alignment', 'compliance_risk', 'claim_substantiation']),
            'marketing.forge' => $this->marketingFlow('marketing.forge', 'Forge', 'MarketingForgeRuntime', 'high', ['campaign_kit', 'asset_manifest', 'experiment_plan', 'learning_delta']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function marketingFlow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'marketing',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasMarketingOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Marketing flow.",
            'autonomy' => $autonomy,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->marketingContextPolicy(),
            'skill_policy' => [
                'preset' => 'domain',
                'required_bundles' => ['marketing-strategy', 'brand-review'],
                'require_skill_trace' => true,
            ],
            'tool_policy' => [
                'mode' => 'workspace_read',
                'external_publish' => false,
            ],
            'memory_policy' => $this->marketingMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['brand_alignment', 'claim_substantiation', 'audience_fit'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => str_ends_with($id, '.forge') ? 'domain_forge_runtime' : 'domain_runtime',
                'quality_required' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_assets' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingContextPolicy(): array
    {
        return [
            'preset' => 'marketing_growth_context',
            'require_context_pack' => true,
            'sources' => [
                'product_offer',
                'icp_personas',
                'brand_voice',
                'competitor_research',
                'campaign_history',
                'performance_metrics',
                'legal_compliance_constraints',
                'asset_library',
            ],
            'budget' => [
                'max_prompt_tokens' => 16000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingMemoryPolicy(): array
    {
        return [
            'projection' => 'marketing',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_campaign', 'performance_snapshot', 'brand_feedback', 'experiment_result'],
                'requires_review_for' => ['brand_voice_rule', 'claim_library', 'audience_insight'],
                'never_auto_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingDomainGatePolicy(): array
    {
        return [
            'required' => ['brand_alignment', 'claim_substantiation', 'audience_fit', 'measurement_plan'],
            'publish_requires' => ['operator_approval', 'compliance_review'],
            'autonomy_ceiling' => 'draft_and_review',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovementContextPolicy(): array
    {
        return [
            'preset' => 'atlas_self_improvement',
            'require_context_pack' => true,
            'sources' => [
                'atlas_evidence_ledger',
                'architecture_validation',
                'domain_onboarding_scorecards',
                'engineering_knowledge_base',
                'code_intelligence',
                'tool_evidence',
                'memory_quality',
            ],
            'lookback_hours_default' => 24,
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovementMemoryPolicy(): array
    {
        return [
            'projection' => 'self_improvement',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['repeated_failure', 'missing_contract', 'regression_trend', 'operator_accepted_proposal'],
                'requires_review_for' => ['process_change', 'domain_promotion', 'runtime_policy_change'],
                'never_auto_apply' => ['destructive_change', 'provider_secret_change', 'security_policy_relaxation'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovementGatePolicy(): array
    {
        return [
            'required' => ['risk_classification', 'evidence_link', 'operator_approval_for_medium_plus'],
            'proposal_requires' => ['reproducible_signal', 'bounded_scope', 'rollback_path'],
            'autonomy_ceiling' => 'proposal_only',
        ];
    }
}

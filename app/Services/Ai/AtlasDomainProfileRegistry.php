<?php

namespace App\Services\Ai;

use App\Services\Ai\Finance\AtlasFinanceDomainContract;
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
                'default_flow' => AtlasFinanceDomainContract::FLOW_MARKET_RESEARCH,
                'orchestrator' => 'AtlasFinanceOrchestrator',
                'runtime_family' => 'finance',
                'description' => 'Financial research, portfolio analysis, risk review, thesis planning, macro and issuer review, compliance checks, and backtest planning for analysis/review only.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->financeContextPolicy(),
                'tool_policy' => $this->financeToolPolicy(),
                'memory_policy' => $this->financeMemoryPolicy(),
                'gate_policy' => $this->financeDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=finance', 'api:ai/domains', 'app:finance', 'mcp:open_brain'],
                    'surface_policy' => [
                        'analysis_review_only' => true,
                        'market_orders_forbidden' => true,
                        'broker_execution_requires_separate_non_ai_system' => true,
                    ],
                    'learning_policy' => [
                        'reviewed_finance_findings_feed_memory' => true,
                        'compliance_blocks_feed_self_improvement' => true,
                    ],
                ],
            ],
            'personal_development' => [
                'id' => 'personal_development',
                'label' => 'Personal Development',
                'status' => 'active',
                'default_flow' => 'personal_development.reflect',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime_family' => 'personal_development',
                'description' => 'Private, non-clinical planning and reflection for habits, routines, focus, energy, learning, personal performance, and life review.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->personalDevelopmentContextPolicy(),
                'memory_policy' => $this->personalDevelopmentMemoryPolicy(),
                'gate_policy' => $this->personalDevelopmentDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=personal_development', 'api:ai/domains', 'app:personal_development', 'mcp:open_brain'],
                    'surface_policy' => [
                        'private_by_default' => true,
                        'plan_and_artifacts_only' => true,
                        'no_auto_calendar_or_task_mutation' => true,
                    ],
                    'learning_policy' => [
                        'accepted_reflections_feed_private_memory' => true,
                        'sensitive_items_require_review_before_promotion' => true,
                    ],
                ],
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
            ...$this->financeStaticFlows(),
            ...$this->personalDevelopmentStaticFlows(),
            ...$this->marketingStaticFlows(),
            ...$this->selfImprovementStaticFlows(),
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
     * @return array<string,array<string,mixed>>
     */
    private function selfImprovementStaticFlows(): array
    {
        $flows = [
            ['self_improvement.nightly_review', 'Nightly Review', 'scheduled_self_improvement', 'Daily evidence-led review that detects gaps, drift, repeated failures, and safe improvement proposals.'],
            ['self_improvement.weekly_architecture_audit', 'Weekly Architecture Audit', 'architecture_audit_runtime', 'Weekly kernel and domain architecture audit over contracts, docs, runtime evidence, and code intelligence.'],
            ['self_improvement.capability_gap_scan', 'Capability Gap Scan', 'capability_gap_runtime', 'Surface and capability scan that finds missing shared capabilities, weak adoption, and duplicated behavior.'],
            ['self_improvement.benchmark_review', 'Benchmark Review', 'benchmark_review_runtime', 'Benchmark corpus review that converts regressions and weak cases into improvement proposals.'],
            ['self_improvement.memory_quality_review', 'Memory Quality Review', 'memory_quality_runtime', 'Memory quality review over source safety, trend drivers, stale knowledge, and accepted learning promotion.'],
            ['self_improvement.tool_runtime_review', 'Tool Runtime Review', 'tool_runtime_review_runtime', 'Super Tool Runtime review over evidence freshness, gate blocks, authority overlap, and missing sensors.'],
            ['self_improvement.repair_loop_review', 'Repair Loop Review', 'repair_loop_review_runtime', 'Dedicated Repair Loop review over repair decisions, blocked/exhausted states, human review recurrence, strategies, reasons, and emitter stages.'],
            ['self_improvement.kernel_pipeline_review', 'Kernel Pipeline Review', 'kernel_pipeline_review_runtime', 'Dedicated kernel pipeline review over surface contract acceptance, rejected plans, input modes, flows, and guard violations.'],
            ['self_improvement.domain_learning_review', 'Domain Learning Review', 'domain_learning_runtime', 'Cross-domain learning review that checks whether domain outcomes are feeding memory, docs, and gates.'],
            ['self_improvement.docs_drift_review', 'Docs Drift Review', 'docs_drift_runtime', 'Documentation drift review comparing kernel contracts, KB docs, code intelligence, and domain manifests.'],
            ['self_improvement.provider_performance_review', 'Provider Performance Review', 'provider_performance_runtime', 'Provider and model performance review over failures, routing decisions, benchmark outcomes, and cost signals.'],
            ['self_improvement.proposal_generation', 'Proposal Generation', 'proposal_generation_runtime', 'Final proposal synthesis flow that emits bounded, reviewable improvement proposals linked to evidence.'],
        ];

        return collect($flows)
            ->mapWithKeys(fn (array $flow): array => [
                $flow[0] => $this->selfImprovementFlow($flow[0], $flow[1], $flow[2], $flow[3]),
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovementFlow(string $id, string $label, string $executor, string $description): array
    {
        return [
            'id' => $id,
            'domain_id' => 'self_improvement',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasSelfImprovementOrchestrator',
            'runtime' => 'SelfImprovementRuntime',
            'description' => $description,
            'autonomy' => 'low',
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'gate_policy' => $this->selfImprovementGatePolicy(),
            'execution_policy' => [
                'executor_preference' => $executor,
                'proposal_only' => true,
                'max_autonomy' => 'low',
            ],
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
    private function financeStaticFlows(): array
    {
        return collect($this->financeContract()->flowDefinitions())
            ->mapWithKeys(fn (array $definition, string $id): array => [
                $id => $this->financeFlow($id, $definition),
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function financeFlow(string $id, array $definition): array
    {
        $requiresApproval = (bool) ($definition['requires_human_approval'] ?? false);

        return [
            'id' => $id,
            'domain_id' => 'finance',
            'label' => (string) $definition['label'],
            'status' => 'active',
            'orchestrator' => 'AtlasFinanceOrchestrator',
            'runtime' => (string) $definition['runtime'],
            'description' => "{$definition['label']} canonical Finance flow for analysis/review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->financeContextPolicy(),
            'tool_policy' => $this->financeToolPolicy(),
            'memory_policy' => $this->financeMemoryPolicy(),
            'gate_policy' => [
                'required' => (array) $definition['required_gates'],
                'requires_final_summary' => true,
                'autonomy_ceiling' => AtlasFinanceDomainContract::OUTPUT_MODE,
                'market_execution_allowed' => false,
            ],
            'execution_policy' => [
                'executor_preference' => $requiresApproval ? 'finance_forge_review_runtime' : 'finance_review_runtime',
                'analysis_only' => true,
                'market_execution_allowed' => false,
                'order_generation_allowed' => false,
                'requires_human_approval' => $requiresApproval,
                'required_evidence' => (array) $definition['required_evidence'],
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
                'requires_human_approval' => $requiresApproval,
                'forbidden_actions' => AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function financeContextPolicy(): array
    {
        return [
            'preset' => 'finance_review_context',
            'require_context_pack' => true,
            'sources' => $this->financeContract()->contextSources(),
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function financeToolPolicy(): array
    {
        return $this->financeContract()->toolPolicy();
    }

    /**
     * @return array<string,mixed>
     */
    private function financeMemoryPolicy(): array
    {
        return $this->financeContract()->memoryPolicy();
    }

    /**
     * @return array<string,mixed>
     */
    private function financeDomainGatePolicy(): array
    {
        return $this->financeContract()->domainGatePolicy();
    }

    private function financeContract(): AtlasFinanceDomainContract
    {
        return new AtlasFinanceDomainContract;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function personalDevelopmentStaticFlows(): array
    {
        return [
            'personal_development.reflect' => $this->personalDevelopmentFlow('personal_development.reflect', 'Reflect', 'reflection_runtime', 'Guided non-clinical reflection that turns observations into evidence and small experiments.', ['privacy_review', 'non_clinical_language']),
            'personal_development.daily_review' => $this->personalDevelopmentFlow('personal_development.daily_review', 'Daily Review', 'daily_review_runtime', 'Daily review of routine evidence, focus, energy, commitments, and next experiment.', ['privacy_review', 'evidence_link']),
            'personal_development.weekly_review' => $this->personalDevelopmentFlow('personal_development.weekly_review', 'Weekly Review', 'weekly_review_runtime', 'Weekly review across goals, routines, learning, energy, and personal operating rhythm.', ['privacy_review', 'evidence_link']),
            'personal_development.habit_design' => $this->personalDevelopmentFlow('personal_development.habit_design', 'Habit Design', 'habit_design_runtime', 'Habit design flow that drafts cues, friction changes, minimum viable routines, and review checkpoints.', ['privacy_review', 'routine_experiment']),
            'personal_development.focus_plan' => $this->personalDevelopmentFlow('personal_development.focus_plan', 'Focus Plan', 'focus_plan_runtime', 'Focus planning flow for attention budget, priority evidence, focus blocks, and interruption policy.', ['privacy_review', 'attention_budget']),
            'personal_development.learning_plan' => $this->personalDevelopmentFlow('personal_development.learning_plan', 'Learning Plan', 'learning_plan_runtime', 'Learning plan flow for skill goals, practice loops, evidence, and lightweight review cadence.', ['privacy_review', 'learning_evidence']),
            'personal_development.energy_review' => $this->personalDevelopmentFlow('personal_development.energy_review', 'Energy Review', 'energy_review_runtime', 'Non-clinical energy review focused on routine patterns, load, recovery evidence, and experiments.', ['privacy_review', 'non_clinical_language', 'evidence_link']),
            'personal_development.goal_decomposition' => $this->personalDevelopmentFlow('personal_development.goal_decomposition', 'Goal Decomposition', 'goal_decomposition_runtime', 'Goal decomposition flow that breaks outcomes into projects, next actions, risks, and review evidence.', ['privacy_review', 'bounded_plan']),
            'personal_development.recovery_plan' => $this->personalDevelopmentFlow('personal_development.recovery_plan', 'Recovery Plan', 'recovery_plan_runtime', 'Non-clinical recovery plan for workload, rest routines, boundaries, and review checkpoints.', ['privacy_review', 'non_clinical_language', 'human_review_for_sensitive']),
            'personal_development.forge' => $this->personalDevelopmentFlow('personal_development.forge', 'Forge', 'personal_development_forge_runtime', 'Integrated personal operating plan across goals, habits, focus, learning, energy, recovery, and review loops.', ['privacy_review', 'human_approval', 'non_clinical_language', 'no_auto_calendar_or_task_changes'], true),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function personalDevelopmentFlow(string $id, string $label, string $executor, string $description, array $requiredGates, bool $approvalRequired = false): array
    {
        return [
            'id' => $id,
            'domain_id' => 'personal_development',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
            'runtime' => 'PersonalDevelopmentRuntime',
            'description' => $description,
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->personalDevelopmentContextPolicy(),
            'memory_policy' => $this->personalDevelopmentMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['privacy_review', 'non_clinical_language', 'no_diagnosis'],
                'sensitive_requires' => ['human_review'],
                'autonomy_ceiling' => 'plan_only',
            ],
            'execution_policy' => [
                'executor_preference' => $executor,
                'plan_and_artifacts_only' => true,
                'calendar_mutation' => false,
                'task_mutation' => false,
                'forge_requires_human_approval' => $approvalRequired,
                'sensitive_recommendations_require_review' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_private_reviewed_patterns' => true,
                    'feed_self_improvement' => true,
                ],
                'approval_required' => $approvalRequired,
                'artifacts' => ['structured_plan', 'review_prompts'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personalDevelopmentContextPolicy(): array
    {
        return [
            'preset' => 'personal_development_private_context',
            'require_context_pack' => true,
            'sources' => [
                'operator_goals',
                'habit_notes',
                'daily_review_notes',
                'weekly_review_notes',
                'focus_logs',
                'learning_notes',
                'energy_observations',
                'routine_experiments',
            ],
            'provider_context_requires_redaction' => true,
            'budget' => [
                'max_prompt_tokens' => 10000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personalDevelopmentMemoryPolicy(): array
    {
        return [
            'projection' => 'personal_development',
            'privacy_default' => 'private',
            'provider_safe_default' => false,
            'provider_safe_only_when_redacted' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_reflection', 'routine_experiment_result', 'reviewed_goal_change'],
                'requires_review_for' => ['sensitive_recommendation', 'identity_level_claim', 'life_review_summary'],
                'never_auto_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personalDevelopmentDomainGatePolicy(): array
    {
        return [
            'required' => ['privacy_review', 'non_clinical_language', 'no_diagnosis', 'evidence_link'],
            'sensitive_requires' => ['human_review'],
            'autonomy_ceiling' => 'plan_only',
            'forbidden' => ['medical_treatment', 'psychological_diagnosis', 'automatic_calendar_or_task_mutation'],
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
                'provider_performance',
                'benchmark_corpus',
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

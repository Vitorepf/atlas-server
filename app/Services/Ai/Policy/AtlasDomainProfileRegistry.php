<?php

namespace App\Services\Ai\Policy;

use App\Models\AiDomainProfile;
use App\Models\AiFlowProfile;
use App\Services\Ai\DomainProfiles\KnowledgeProfileBuilder;
use App\Services\Ai\DomainProfiles\AssuranceProfileBuilder;
use App\Services\Ai\DomainProfiles\EnterpriseProfileBuilder;
use App\Services\Ai\DomainProfiles\PersonalProfileBuilder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

class AtlasDomainProfileRegistry
{
    public function __construct(
        private readonly KnowledgeProfileBuilder $knowledgeProfiles = new KnowledgeProfileBuilder,
        private readonly AssuranceProfileBuilder $assuranceProfiles = new AssuranceProfileBuilder,
        private readonly EnterpriseProfileBuilder $enterpriseProfiles = new EnterpriseProfileBuilder,
        private readonly PersonalProfileBuilder $personalProfiles = new PersonalProfileBuilder,
    ) {}

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
        if (! DatabaseTableAvailability::all(['ai_domain_profiles', 'ai_flow_profiles'])) {
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
        if (! DatabaseTableAvailability::all(['ai_domain_profiles', 'ai_flow_profiles'])) {
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
            ...$this->knowledgeProfiles->domains(),
            ...$this->assuranceProfiles->domains(),
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
            ...$this->enterpriseProfiles->domains(),
            ...$this->personalProfiles->domains(),
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
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function staticFlows(): array
    {
        return [
            ...$this->knowledgeProfiles->flows(),
            ...$this->assuranceProfiles->flows(),
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
            'programming.frontend' => [
                'id' => 'programming.frontend',
                'domain_id' => 'programming',
                'label' => 'Frontend',
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
                'gate_policy' => $this->programmingFlowGatePolicy([
                    'frontend_context_pack',
                    'visual_evidence',
                    'responsive_check',
                    'a11y_or_reason',
                    'asset_provenance',
                    'design_review',
                ], 'engineering_harness'),
                'metadata' => array_merge($this->programmingFlowMetadata(), [
                    'specialist_profile' => 'programming.frontend',
                    'owner_doc' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
                ]),
            ],
            ...$this->enterpriseProfiles->flows(),
            ...$this->personalProfiles->flows(),
            ...$this->selfImprovementStaticFlows(),
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
            ['self_improvement.provider_release_review', 'Provider Release Review', 'provider_release_review_runtime', 'Dedicated Provider Evolution review over releases, Rivals requirements, skill-pack absorption, Decide signals, and hardcode risk.'],
            ['self_improvement.provider_performance_review', 'Provider Performance Review', 'provider_performance_runtime', 'Provider and model performance review over failures, routing decisions, benchmark outcomes, and cost signals.'],
            ['self_improvement.agent_behavior_review', 'Agent Behavior Review', 'agent_behavior_review_runtime', 'Dedicated agent behavior review over verification gaps, unsurgical diffs, instruction drift, repeated finding codes, and provider/model clusters.'],
            ['self_improvement.voice_realtime_review', 'Voice Realtime Review', 'voice_realtime_review_runtime', 'Dedicated Voice Realtime review over readiness, runtime certification, Rivals-Voice baseline, VOICE_* evidence, and privacy gates.'],
            ['self_improvement.failure_pattern_review', 'Failure Pattern Review', 'failure_pattern_review_runtime', 'Dedicated failure signature review over recurrence, diversity index, stagnant repeated failures, and reviewable improvement proposals.'],
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

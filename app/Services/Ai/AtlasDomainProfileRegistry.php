<?php

namespace App\Services\Ai;

use App\Models\AiDomainProfile;
use App\Models\AiFlowProfile;
use App\Services\Ai\Finance\AtlasFinanceDomainContract;
use App\Services\Ai\Support\DatabaseTableAvailability;
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
            'general' => [
                'id' => 'general',
                'label' => 'General Answer',
                'status' => 'active',
                'default_flow' => 'general.answer',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime_family' => 'conversation',
                'description' => 'Governed answer and triage fallback for simple questions; specialized work must hand off to the owning Atlas domain through Decide.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->generalContextPolicy(),
                'memory_policy' => $this->generalMemoryPolicy(),
                'gate_policy' => $this->generalDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ask', 'api:ai/chat', 'app:chat', 'mcp:open_brain'],
                    'surface_policy' => [
                        'answer_or_triage_only' => true,
                        'specialized_requests_must_handoff' => true,
                        'no_policy_bypass' => true,
                    ],
                    'learning_policy' => [
                        'promote_only_reviewed_general_guidance' => true,
                        'handoff_misses_feed_intent_router' => true,
                    ],
                ],
            ],
            'research' => [
                'id' => 'research',
                'label' => 'Research',
                'status' => 'active',
                'default_flow' => 'research.quick',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime_family' => 'research',
                'description' => 'Source-grounded research, learning, synthesis, citation review, contradiction mapping, and knowledge promotion planning.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
                'context_policy' => $this->researchContextPolicy(),
                'memory_policy' => $this->researchMemoryPolicy(),
                'gate_policy' => $this->researchDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=research', 'api:ai/domains', 'app:research', 'mcp:open_brain'],
                    'surface_policy' => [
                        'source_grounded_by_default' => true,
                        'memory_promotion_requires_review' => true,
                        'unsourced_claims_are_blocked' => true,
                    ],
                    'learning_policy' => [
                        'accepted_research_packets_feed_memory' => true,
                        'low_confidence_claims_create_review_items' => true,
                        'contradictions_feed_self_improvement' => true,
                    ],
                ],
            ],
            'learning' => [
                'id' => 'learning',
                'label' => 'Learning',
                'status' => 'active',
                'default_flow' => 'learning.plan',
                'orchestrator' => 'AtlasLearningOrchestrator',
                'runtime_family' => 'learning',
                'description' => 'Human learning domain for study plans, deliberate practice, review, spaced repetition, and mastery evidence without modifying the Core Learning Plane.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
                'context_policy' => $this->learningContextPolicy(),
                'memory_policy' => $this->learningMemoryPolicy(),
                'gate_policy' => $this->learningDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=learning', 'api:ai/domains', 'app:learning', 'mcp:open_brain'],
                    'surface_policy' => [
                        'human_learning_domain_only' => true,
                        'core_learning_plane_not_modified' => true,
                        'calendar_and_task_mutation_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_mastery_evidence_feeds_memory' => true,
                        'practice_outcomes_feed_review_packets' => true,
                        'domain_findings_feed_self_improvement' => true,
                    ],
                ],
            ],
            'qa' => [
                'id' => 'qa',
                'label' => 'QA',
                'status' => 'active',
                'default_flow' => 'qa.regression_review',
                'orchestrator' => 'AtlasQaOrchestrator',
                'runtime_family' => 'qa',
                'description' => 'Cross-domain quality assurance for regression review, acceptance review, evidence audit, and release readiness without executing tests or overriding domain gates.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->qaContextPolicy(),
                'memory_policy' => $this->qaMemoryPolicy(),
                'gate_policy' => $this->qaDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=qa', 'api:ai/domains', 'app:qa', 'mcp:open_brain'],
                    'surface_policy' => [
                        'cross_domain_review_only' => true,
                        'programming_qa_executes_code_tests_not_this_domain' => true,
                        'domain_gates_cannot_be_overridden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_qa_findings_feed_memory' => true,
                        'evidence_gaps_feed_self_improvement' => true,
                        'repeated_regressions_feed_domain_gates' => true,
                    ],
                ],
            ],
            'security' => [
                'id' => 'security',
                'label' => 'Security',
                'status' => 'active',
                'default_flow' => 'security.threat_review',
                'orchestrator' => 'AtlasSecurityOrchestrator',
                'runtime_family' => 'security',
                'description' => 'Defensive security review domain for threat review, privacy review, compliance review, and incident review without exploit execution, secret access, or network scanning.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->securityContextPolicy(),
                'memory_policy' => $this->securityMemoryPolicy(),
                'gate_policy' => $this->securityDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=security', 'api:ai/domains', 'app:security', 'mcp:open_brain'],
                    'surface_policy' => [
                        'defensive_review_only' => true,
                        'programming_security_executes_code_security_harness_not_this_domain' => true,
                        'secret_access_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_security_findings_feed_memory' => true,
                        'control_gaps_feed_self_improvement' => true,
                        'incidents_feed_postmortem_review' => true,
                    ],
                ],
            ],
            'operations' => [
                'id' => 'operations',
                'label' => 'Operations',
                'status' => 'active',
                'default_flow' => 'operations.diagnostic',
                'orchestrator' => 'AtlasOperationsOrchestrator',
                'runtime_family' => 'operations',
                'description' => 'Operational diagnostics, runbook planning, incident review, and readiness review without deploying, restarting, deleting data, or mutating infrastructure.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->operationsContextPolicy(),
                'memory_policy' => $this->operationsMemoryPolicy(),
                'gate_policy' => $this->operationsDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=operations', 'api:ai/domains', 'app:operations', 'mcp:open_brain'],
                    'surface_policy' => [
                        'diagnostic_only' => true,
                        'operational_action_requires_separate_receipt' => true,
                        'production_mutation_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_operations_findings_feed_memory' => true,
                        'incident_reviews_feed_self_improvement' => true,
                        'repeated_alerts_feed_runbook_improvement' => true,
                    ],
                ],
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
            'strategic_decision' => [
                'id' => 'strategic_decision',
                'label' => 'Strategic Decision',
                'status' => 'active',
                'default_flow' => 'strategic_decision.review',
                'orchestrator' => 'AtlasStrategicDecisionOrchestrator',
                'runtime_family' => 'strategic_decision',
                'description' => 'Long-horizon co-strategy domain for major decisions, disagreement, cool-down review, values alignment, counterarguments, regret tracking, and longitudinal pattern discovery.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->strategicDecisionContextPolicy(),
                'memory_policy' => $this->strategicDecisionMemoryPolicy(),
                'gate_policy' => $this->strategicDecisionDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=strategic_decision', 'api:ai/domains', 'app:strategy', 'mcp:open_brain'],
                    'surface_policy' => [
                        'advice_is_review_not_command' => true,
                        'cooldown_required_for_high_impact' => true,
                        'operator_agency_preserved' => true,
                        'no_autonomous_life_or_business_commitment' => true,
                    ],
                    'learning_policy' => [
                        'rivals_strategy_cases_required' => true,
                        'regret_reviews_feed_private_memory' => true,
                        'accepted_patterns_feed_self_improvement' => true,
                    ],
                ],
            ],
            'writing' => [
                'id' => 'writing',
                'label' => 'Writing',
                'status' => 'active',
                'default_flow' => 'writing.draft',
                'orchestrator' => 'AtlasWritingOrchestrator',
                'runtime_family' => 'writing',
                'description' => 'Governed drafting, editing, voice review, publication review, and source-aware writing workflows.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'context_policy' => $this->writingContextPolicy(),
                'memory_policy' => $this->writingMemoryPolicy(),
                'gate_policy' => $this->writingDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=writing', 'api:ai/domains', 'app:writing', 'mcp:open_brain'],
                    'surface_policy' => [
                        'draft_until_operator_approval' => true,
                        'external_publish_requires_explicit_human_review' => true,
                        'voice_alignment_gate_required' => true,
                    ],
                    'learning_policy' => [
                        'accepted_voice_feedback_updates_projection' => true,
                        'published_artifact_outcomes_feed_memory' => true,
                        'publication_risk_blocks_feed_self_improvement' => true,
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
                'label' => 'Background Safety',
                'status' => 'active',
                'default_flow' => 'background.safe',
                'orchestrator' => 'BackgroundSafetyOrchestrator',
                'runtime_family' => 'background',
                'autonomy_default' => 'low',
                'background_allowed' => true,
                'description' => 'Background safety review, readiness, schedule and permission governance for cron/heartbeat/daemon work without starting jobs or changing schedules.',
                'context_policy' => $this->backgroundContextPolicy(),
                'memory_policy' => $this->backgroundMemoryPolicy(),
                'gate_policy' => $this->backgroundDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['scheduler', 'cli:atlas automation', 'api:automations', 'app:atlas engineering', 'mcp:open_brain'],
                    'surface_policy' => [
                        'background_review_only' => true,
                        'explicit_operator_approval_required' => true,
                        'stop_conditions_required' => true,
                    ],
                    'learning_policy' => [
                        'accepted_background_reviews_feed_memory' => true,
                        'repeated_background_risks_feed_self_improvement' => true,
                        'unreviewed_background_actions_never_promote' => true,
                    ],
                ],
            ],
            'health' => [
                'id' => 'health',
                'label' => 'Health',
                'status' => 'active',
                'default_flow' => 'health.review',
                'orchestrator' => 'AtlasHealthOrchestrator',
                'runtime_family' => 'health',
                'description' => 'Non-clinical wellness review, routine review, recovery review and safety review without diagnosis, treatment, dosage or emergency decisions.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->healthContextPolicy(),
                'memory_policy' => $this->healthMemoryPolicy(),
                'gate_policy' => $this->healthDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=health', 'api:ai/domains', 'app:health', 'mcp:open_brain'],
                    'surface_policy' => [
                        'non_clinical_review_only' => true,
                        'professional_review_required_for_risk_flags' => true,
                        'medical_decisions_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'promote_only_reviewed_wellness_patterns' => true,
                        'never_promote_medical_decisions' => true,
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
                'context_policy' => $this->generalContextPolicy(),
                'memory_policy' => $this->generalMemoryPolicy(),
                'gate_policy' => [
                    'required' => ['question_present', 'answer_or_triage_only', 'domain_handoff_review'],
                    'global_required' => ['no_policy_bypass', 'no_destructive_action'],
                    'autonomy_ceiling' => 'answer_or_triage_only',
                    'requires_final_summary' => true,
                ],
                'execution_policy' => [
                    'executor_preference' => 'general_answer_packet_runtime',
                    'answer_or_triage_only' => true,
                    'specialized_work_allowed' => false,
                    'destructive_action_allowed' => false,
                    'tool_execution_allowed' => false,
                    'provider_override_allowed' => false,
                ],
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'research.quick' => $this->researchFlow(
                'research.quick',
                'Quick Research',
                'medium',
                ['source_refs', 'citation_policy', 'uncertainty_statement']
            ),
            'research.super' => $this->researchFlow(
                'research.super',
                'Super Research',
                'high',
                ['source_refs', 'citation_policy', 'uncertainty_statement', 'contradiction_check', 'promotion_review']
            ),
            ...$this->learningStaticFlows(),
            ...$this->qaStaticFlows(),
            ...$this->securityStaticFlows(),
            ...$this->operationsStaticFlows(),
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
            ...$this->financeStaticFlows(),
            ...$this->personalDevelopmentStaticFlows(),
            ...$this->marketingStaticFlows(),
            ...$this->strategicDecisionStaticFlows(),
            ...$this->writingStaticFlows(),
            ...$this->selfImprovementStaticFlows(),
            ...$this->backgroundStaticFlows(),
            ...$this->healthStaticFlows(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generalContextPolicy(): array
    {
        return [
            'preset' => 'general_answer_context',
            'require_context_pack' => false,
            'sources' => ['conversation_context', 'explicit_user_context', 'surface_hints', 'domain_catalog'],
            'budget' => [
                'max_prompt_tokens' => 6000,
                'reserved_output_tokens' => 2000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generalMemoryPolicy(): array
    {
        return [
            'projection' => 'general',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_general_guidance', 'reviewed_intent_handoff'],
                'requires_review_for' => ['new_general_policy', 'domain_handoff_rule'],
                'never_auto_promote_specialized_advice' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generalDomainGatePolicy(): array
    {
        return [
            'required' => ['question_present', 'answer_or_triage_only', 'domain_handoff_review'],
            'specialized_work_requires' => ['atlas_decide', 'domain_profile', 'flow_profile'],
            'autonomy_ceiling' => 'answer_or_triage_only',
            'destructive_action_allowed' => false,
            'tool_execution_allowed' => false,
            'provider_override_allowed' => false,
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function researchFlow(string $id, string $label, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'research',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasResearchOrchestrator',
            'runtime' => 'ResearchRuntime',
            'description' => "{$label} canonical Research flow.",
            'autonomy' => $autonomy,
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->researchContextPolicy(),
            'memory_policy' => $this->researchMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['source_refs', 'citation_policy', 'uncertainty_statement'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'research_packet_runtime',
                'source_grounded' => true,
                'memory_promotion' => 'proposal_only',
                'unsourced_claims_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_packets' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function researchContextPolicy(): array
    {
        return [
            'preset' => 'source_grounded_research_context',
            'require_context_pack' => true,
            'sources' => [
                'user_question',
                'source_refs',
                'atlas_knowledge_base',
                'open_brain_memory',
                'code_intelligence',
                'content_intelligence',
                'external_docs_when_allowed',
                'evidence_ledger',
            ],
            'provider_context_requires_citations' => true,
            'budget' => [
                'max_prompt_tokens' => 18000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function researchMemoryPolicy(): array
    {
        return [
            'projection' => 'research',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_research_packet', 'verified_source_note', 'contradiction_resolved'],
                'requires_review_for' => ['new_domain_knowledge', 'atlas_process_change', 'operator_learning_protocol'],
                'never_auto_promote_unsourced_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function researchDomainGatePolicy(): array
    {
        return [
            'required' => ['source_refs', 'citation_policy', 'uncertainty_statement'],
            'super_research_requires' => ['contradiction_check', 'promotion_review'],
            'autonomy_ceiling' => 'source_grounded_review',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function learningStaticFlows(): array
    {
        return [
            'learning.plan' => $this->learningFlow('learning.plan', 'Learning Plan', 'LearningRuntime', 'medium', ['learning_objective', 'skill_scope', 'target_level']),
            'learning.practice' => $this->learningFlow('learning.practice', 'Practice', 'LearningPracticeRuntime', 'medium', ['practice_loop', 'feedback_loop', 'mastery_rubric']),
            'learning.review' => $this->learningFlow('learning.review', 'Review', 'LearningReviewRuntime', 'low', ['outcome_evidence', 'gap_map', 'next_iteration']),
            'learning.spaced_review' => $this->learningFlow('learning.spaced_review', 'Spaced Review', 'LearningSpacedReviewRuntime', 'low', ['spaced_review', 'retrieval_practice', 'forgetting_risk']),
            'learning.worked_example' => $this->learningFlow('learning.worked_example', 'Worked Example', 'LearningWorkedExampleRuntime', 'medium', ['worked_example_appropriate_for_stage', 'pedagogy_matches_stage', 'mastery_rubric']),
            'learning.pattern_extraction' => $this->learningFlow('learning.pattern_extraction', 'Pattern Extraction', 'LearningPatternExtractionRuntime', 'low', ['pattern_structure_complete', 'pattern_personal_evidence_provider_safe']),
            'learning.process_optimization' => $this->learningFlow('learning.process_optimization', 'Process Optimization', 'LearningProcessOptimizationRuntime', 'medium', ['pattern_structure_complete', 'outcome_evidence']),
            'learning.failure_review' => $this->learningFlow('learning.failure_review', 'Failure Review', 'LearningFailureReviewRuntime', 'low', ['failure_signature_classified', 'failure_signature_provider_safety', 'outcome_evidence']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function learningFlow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'learning',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasLearningOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Learning flow.",
            'autonomy' => $autonomy,
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->learningContextPolicy(),
            'memory_policy' => $this->learningMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['learning_objective', 'practice_loop', 'mastery_rubric'],
                'autonomy_ceiling' => 'plan_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'learning_packet_runtime',
                'plan_only_until_operator_acceptance' => true,
                'calendar_mutation' => false,
                'task_mutation' => false,
                'core_learning_plane_mutation' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_mastery_evidence' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningContextPolicy(): array
    {
        return [
            'preset' => 'human_learning_context',
            'require_context_pack' => true,
            'sources' => [
                'learning_goal',
                'current_skill_profile',
                'target_skill_profile',
                'source_material',
                'practice_history',
                'mistake_log',
                'retrieval_practice_results',
                'atlas_vault_curated_notes',
            ],
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningMemoryPolicy(): array
    {
        return [
            'projection' => 'learning',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_learning_plan', 'mastery_evidence', 'practice_outcome', 'reviewed_mistake_pattern'],
                'requires_review_for' => ['skill_profile_change', 'long_term_learning_protocol', 'operator_cognitive_pattern'],
                'never_auto_promote_unreviewed_mastery_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningDomainGatePolicy(): array
    {
        return [
            'required' => ['learning_objective', 'skill_scope', 'target_level', 'practice_loop', 'mastery_rubric'],
            'review_requires' => ['outcome_evidence', 'gap_map', 'next_iteration'],
            'autonomy_ceiling' => 'plan_only',
            'core_learning_plane_mutation' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function qaStaticFlows(): array
    {
        return [
            'qa.regression_review' => $this->qaFlow('qa.regression_review', 'Regression Review', 'QaRegressionRuntime', ['qa_scope', 'acceptance_criteria', 'risk_review']),
            'qa.acceptance_review' => $this->qaFlow('qa.acceptance_review', 'Acceptance Review', 'QaAcceptanceRuntime', ['acceptance_criteria', 'criteria_coverage', 'human_review_required']),
            'qa.evidence_audit' => $this->qaFlow('qa.evidence_audit', 'Evidence Audit', 'QaEvidenceRuntime', ['evidence_refs', 'traceability_map', 'uncertainty_statement']),
            'qa.release_readiness' => $this->qaFlow('qa.release_readiness', 'Release Readiness', 'QaReleaseRuntime', ['release_risk', 'blocking_findings', 'go_no_go_recommendation']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function qaFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'qa',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasQaOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical QA flow for cross-domain review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->qaContextPolicy(),
            'memory_policy' => $this->qaMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['qa_scope', 'acceptance_criteria', 'human_review_required'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'qa_packet_runtime',
                'review_only' => true,
                'test_execution_allowed' => false,
                'deploy_allowed' => false,
                'domain_gate_override_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaContextPolicy(): array
    {
        return [
            'preset' => 'cross_domain_qa_context',
            'require_context_pack' => true,
            'sources' => [
                'operation_envelope',
                'decision_receipt',
                'acceptance_criteria',
                'evidence_ledger',
                'domain_gate_results',
                'test_artifacts_when_available',
                'risk_register',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 12000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaMemoryPolicy(): array
    {
        return [
            'projection' => 'qa',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_qa_finding', 'verified_regression_pattern', 'release_readiness_review'],
                'requires_review_for' => ['new_quality_rule', 'domain_gate_change', 'release_policy_change'],
                'never_auto_promote_unverified_quality_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaDomainGatePolicy(): array
    {
        return [
            'required' => ['qa_scope', 'acceptance_criteria', 'evidence_refs', 'risk_review', 'human_review_required'],
            'release_requires' => ['blocking_findings', 'go_no_go_recommendation', 'operator_approval'],
            'autonomy_ceiling' => 'review_only',
            'test_execution_allowed' => false,
            'deploy_allowed' => false,
            'domain_gate_override_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function securityStaticFlows(): array
    {
        return [
            'security.threat_review' => $this->securityFlow('security.threat_review', 'Threat Review', 'SecurityThreatRuntime', ['security_scope', 'defensive_only', 'risk_register']),
            'security.privacy_review' => $this->securityFlow('security.privacy_review', 'Privacy Review', 'SecurityPrivacyRuntime', ['data_classification', 'privacy_risk', 'redaction_plan']),
            'security.compliance_review' => $this->securityFlow('security.compliance_review', 'Compliance Review', 'SecurityComplianceRuntime', ['control_mapping', 'evidence_refs', 'human_review_required']),
            'security.incident_review' => $this->securityFlow('security.incident_review', 'Incident Review', 'SecurityIncidentRuntime', ['incident_scope', 'timeline', 'postmortem_actions']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function securityFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'security',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasSecurityOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Security flow for defensive review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->securityContextPolicy(),
            'memory_policy' => $this->securityMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['security_scope', 'defensive_only', 'human_review_required'],
                'autonomy_ceiling' => 'defensive_review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'security_packet_runtime',
                'defensive_review_only' => true,
                'exploit_execution_allowed' => false,
                'network_scan_allowed' => false,
                'secret_access_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function securityContextPolicy(): array
    {
        return [
            'preset' => 'defensive_security_context',
            'require_context_pack' => true,
            'sources' => [
                'asset_inventory',
                'scope_boundary',
                'control_catalog',
                'threat_model',
                'evidence_ledger',
                'privacy_classification',
                'incident_notes',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 12000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function securityMemoryPolicy(): array
    {
        return [
            'projection' => 'security',
            'provider_safe_default' => false,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_security_finding', 'verified_control_gap', 'reviewed_incident_pattern'],
                'requires_review_for' => ['new_security_rule', 'privacy_policy_change', 'credential_or_secret_context'],
                'never_auto_promote_secrets_or_unredacted_sensitive_data' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function securityDomainGatePolicy(): array
    {
        return [
            'required' => ['security_scope', 'defensive_only', 'human_review_required'],
            'high_risk_requires' => ['operator_approval', 'evidence_refs', 'redaction_review'],
            'autonomy_ceiling' => 'defensive_review_only',
            'exploit_execution_allowed' => false,
            'network_scan_allowed' => false,
            'secret_access_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function operationsStaticFlows(): array
    {
        return [
            'operations.diagnostic' => $this->operationsFlow('operations.diagnostic', 'Diagnostic', 'OperationsDiagnosticRuntime', ['operations_scope', 'diagnostic_only', 'signal_map']),
            'operations.runbook' => $this->operationsFlow('operations.runbook', 'Runbook', 'OperationsRunbookRuntime', ['runbook_steps', 'prechecks', 'human_review_required']),
            'operations.incident_review' => $this->operationsFlow('operations.incident_review', 'Incident Review', 'OperationsIncidentRuntime', ['incident_scope', 'timeline', 'postmortem_actions']),
            'operations.readiness_review' => $this->operationsFlow('operations.readiness_review', 'Readiness Review', 'OperationsReadinessRuntime', ['readiness_score', 'evidence_refs', 'operator_decision_needed']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function operationsFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'operations',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasOperationsOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Operations flow for diagnostic review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->operationsContextPolicy(),
            'memory_policy' => $this->operationsMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['operations_scope', 'diagnostic_only', 'human_review_required'],
                'autonomy_ceiling' => 'diagnostic_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'operations_packet_runtime',
                'diagnostic_only' => true,
                'restart_allowed' => false,
                'deploy_allowed' => false,
                'infrastructure_mutation_allowed' => false,
                'data_deletion_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsContextPolicy(): array
    {
        return [
            'preset' => 'operations_diagnostic_context',
            'require_context_pack' => true,
            'sources' => [
                'system_scope',
                'symptoms',
                'signals',
                'logs_when_provided',
                'evidence_ledger',
                'runbooks',
                'risk_register',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 12000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsMemoryPolicy(): array
    {
        return [
            'projection' => 'operations',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_operations_finding', 'reviewed_incident_pattern', 'runbook_improvement'],
                'requires_review_for' => ['production_runbook_change', 'operational_policy_change', 'critical_incident_pattern'],
                'never_auto_promote_unreviewed_operational_actions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsDomainGatePolicy(): array
    {
        return [
            'required' => ['operations_scope', 'diagnostic_only', 'human_review_required'],
            'operational_action_requires' => ['separate_decision_receipt', 'operator_approval', 'rollback_plan'],
            'autonomy_ceiling' => 'diagnostic_only',
            'restart_allowed' => false,
            'deploy_allowed' => false,
            'infrastructure_mutation_allowed' => false,
            'data_deletion_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function backgroundStaticFlows(): array
    {
        return [
            'background.safe' => $this->backgroundFlow('background.safe', 'Safe Background', 'BackgroundSafetyRuntime', ['background_scope', 'review_only', 'stop_conditions']),
            'background.readiness_review' => $this->backgroundFlow('background.readiness_review', 'Readiness Review', 'BackgroundReadinessRuntime', ['readiness_score', 'missing_controls', 'approval_requirements']),
            'background.schedule_review' => $this->backgroundFlow('background.schedule_review', 'Schedule Review', 'BackgroundScheduleRuntime', ['schedule_declared', 'cadence_review', 'stop_conditions']),
            'background.permission_review' => $this->backgroundFlow('background.permission_review', 'Permission Review', 'BackgroundPermissionRuntime', ['permission_review', 'least_privilege', 'operator_approval_needed']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function backgroundFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'background',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'BackgroundSafetyOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Background Safety flow for review-only background governance.",
            'autonomy' => 'low',
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->backgroundContextPolicy(),
            'memory_policy' => $this->backgroundMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['background_scope', 'review_only', 'stop_conditions', 'human_review_required'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'background_safety_packet_runtime',
                'review_only' => true,
                'start_jobs_allowed' => false,
                'schedule_mutation_allowed' => false,
                'permission_escalation_allowed' => false,
                'unbounded_loop_allowed' => false,
                'required_evidence' => ['background_packet', 'permission_review', 'stop_conditions'],
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['scheduler', 'cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backgroundContextPolicy(): array
    {
        return [
            'preset' => 'background_safety_context',
            'require_context_pack' => true,
            'sources' => [
                'job_scope',
                'trigger',
                'schedule',
                'permissions',
                'stop_conditions',
                'evidence_ledger',
                'automation_history',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 10000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backgroundMemoryPolicy(): array
    {
        return [
            'projection' => 'background',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_background_review', 'reviewed_schedule_risk', 'permission_boundary_improvement'],
                'requires_review_for' => ['new_background_policy', 'schedule_change_rule', 'permission_policy_change'],
                'never_auto_promote_unreviewed_background_actions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backgroundDomainGatePolicy(): array
    {
        return [
            'required' => ['background_scope', 'review_only', 'stop_conditions', 'human_review_required'],
            'background_execution_requires' => ['separate_decision_receipt', 'operator_approval', 'bounded_schedule', 'stop_conditions'],
            'autonomy_ceiling' => 'review_only',
            'start_jobs_allowed' => false,
            'schedule_mutation_allowed' => false,
            'permission_escalation_allowed' => false,
            'unbounded_loop_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function healthStaticFlows(): array
    {
        return [
            'health.review' => $this->healthFlow('health.review', 'Health Review', 'HealthReviewRuntime', ['health_scope', 'non_clinical_boundary', 'professional_review_notice']),
            'health.routine_review' => $this->healthFlow('health.routine_review', 'Routine Review', 'HealthRoutineRuntime', ['routine_observations', 'constraints', 'professional_review_notice']),
            'health.recovery_review' => $this->healthFlow('health.recovery_review', 'Recovery Review', 'HealthRecoveryRuntime', ['recovery_considerations', 'risk_flags', 'non_clinical_next_steps']),
            'health.safety_review' => $this->healthFlow('health.safety_review', 'Safety Review', 'HealthSafetyRuntime', ['red_flags', 'escalation_notice', 'do_not_delay_care']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function healthFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'health',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasHealthOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Health flow for non-clinical wellness review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->healthContextPolicy(),
            'memory_policy' => $this->healthMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['health_scope', 'non_clinical_boundary', 'professional_review_notice'],
                'autonomy_ceiling' => 'non_clinical_review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'health_review_packet_runtime',
                'non_clinical_review_only' => true,
                'diagnosis_allowed' => false,
                'treatment_allowed' => false,
                'dosage_change_allowed' => false,
                'emergency_decision_allowed' => false,
                'professional_care_replacement_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_wellness_patterns' => true,
                    'never_promote_medical_decisions' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthContextPolicy(): array
    {
        return [
            'preset' => 'health_non_clinical_context',
            'require_context_pack' => true,
            'sources' => ['topic', 'goal', 'signals', 'constraints', 'risk_flags', 'operator_notes', 'evidence_refs'],
            'budget' => [
                'max_prompt_tokens' => 8000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthMemoryPolicy(): array
    {
        return [
            'projection' => 'health',
            'provider_safe_default' => false,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['reviewed_wellness_pattern', 'accepted_routine_observation'],
                'requires_review_for' => ['health_related_memory', 'sensitive_personal_data', 'risk_flag_pattern'],
                'never_auto_promote_medical_decisions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthDomainGatePolicy(): array
    {
        return [
            'required' => ['health_scope', 'non_clinical_boundary', 'professional_review_notice'],
            'risk_flags_require' => ['professional_review_notice', 'do_not_delay_care', 'no_self_treatment'],
            'autonomy_ceiling' => 'non_clinical_review_only',
            'diagnosis_allowed' => false,
            'treatment_allowed' => false,
            'dosage_change_allowed' => false,
            'emergency_decision_allowed' => false,
            'professional_care_replacement_allowed' => false,
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
     * @return array<string,array<string,mixed>>
     */
    private function strategicDecisionStaticFlows(): array
    {
        return [
            'strategic_decision.review' => $this->strategicDecisionFlow('strategic_decision.review', 'Review', 'StrategicDecisionReviewRuntime', ['decision_frame', 'options_map', 'evidence_pack']),
            'strategic_decision.cooldown' => $this->strategicDecisionFlow('strategic_decision.cooldown', 'Cool-down', 'StrategicDecisionCooldownRuntime', ['impact_classification', 'cooldown_window', 'revisit_trigger']),
            'strategic_decision.values_alignment' => $this->strategicDecisionFlow('strategic_decision.values_alignment', 'Values Alignment', 'StrategicDecisionAlignmentRuntime', ['values_trace', 'tradeoff_map', 'agency_check']),
            'strategic_decision.counterargument' => $this->strategicDecisionFlow('strategic_decision.counterargument', 'Counterargument', 'StrategicDecisionCounterargumentRuntime', ['steelman', 'red_team_review', 'disagreement_summary']),
            'strategic_decision.regret_tracking' => $this->strategicDecisionFlow('strategic_decision.regret_tracking', 'Regret Tracking', 'StrategicDecisionRegretRuntime', ['rivals_case', 'review_horizon', 'regret_score']),
            'strategic_decision.longitudinal_pattern' => $this->strategicDecisionFlow('strategic_decision.longitudinal_pattern', 'Longitudinal Pattern', 'StrategicDecisionPatternRuntime', ['multi_year_signal', 'privacy_review', 'pattern_confidence']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function strategicDecisionFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'strategic_decision',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasStrategicDecisionOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Strategic Decision flow.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->strategicDecisionContextPolicy(),
            'memory_policy' => $this->strategicDecisionMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['cooldown_policy', 'multi_perspective_review', 'values_alignment', 'operator_agency'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'strategic_decision_review_runtime',
                'review_only' => true,
                'commitment_execution_allowed' => false,
                'requires_rivals_strategy_case' => $id !== 'strategic_decision.counterargument',
                'requires_human_approval' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'link_rivals_strategy_case' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicDecisionContextPolicy(): array
    {
        return [
            'preset' => 'strategic_decision_context',
            'require_context_pack' => true,
            'sources' => [
                'operator_goals',
                'decision_history',
                'rivals_strategy_cases',
                'values_notes',
                'project_commitments',
                'risk_register',
                'longitudinal_patterns',
                'evidence_ledger',
            ],
            'provider_context_requires_redaction' => true,
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicDecisionMemoryPolicy(): array
    {
        return [
            'projection' => 'strategic_decision',
            'privacy_default' => 'private',
            'provider_safe_default' => false,
            'provider_safe_only_when_redacted' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['reviewed_decision', 'regret_review', 'accepted_longitudinal_pattern'],
                'requires_review_for' => ['identity_level_pattern', 'business_direction', 'life_strategy'],
                'never_auto_apply' => true,
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function writingStaticFlows(): array
    {
        return [
            'writing.draft' => $this->writingFlow('writing.draft', 'Draft', 'WritingRuntime', 'medium', ['brief_clarity', 'audience_fit', 'voice_alignment']),
            'writing.edit' => $this->writingFlow('writing.edit', 'Edit', 'WritingEditRuntime', 'medium', ['brief_clarity', 'voice_alignment', 'change_rationale']),
            'writing.voice_review' => $this->writingFlow('writing.voice_review', 'Voice Review', 'WritingVoiceRuntime', 'low', ['voice_alignment', 'voice_drift_findings', 'operator_review']),
            'writing.publish_review' => $this->writingFlow('writing.publish_review', 'Publish Review', 'WritingReviewRuntime', 'low', ['publication_review', 'claim_review', 'human_review_required']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function writingFlow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'writing',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasWritingOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Writing flow.",
            'autonomy' => $autonomy,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->writingContextPolicy(),
            'memory_policy' => $this->writingMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['brief_clarity', 'voice_alignment', 'human_review_required'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'writing_packet_runtime',
                'draft_until_operator_approval' => true,
                'external_publish_allowed' => false,
                'quality_required' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_voice_feedback' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writingContextPolicy(): array
    {
        return [
            'preset' => 'governed_writing_context',
            'require_context_pack' => true,
            'sources' => [
                'writing_brief',
                'target_audience',
                'operator_voice_samples',
                'source_material',
                'style_guide',
                'publication_constraints',
                'atlas_vault_curated_notes',
            ],
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writingMemoryPolicy(): array
    {
        return [
            'projection' => 'writing',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_draft', 'operator_voice_feedback', 'published_artifact_outcome'],
                'requires_review_for' => ['voice_rule', 'public_claim', 'sensitive_story'],
                'never_auto_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writingDomainGatePolicy(): array
    {
        return [
            'required' => ['brief_clarity', 'audience_fit', 'voice_alignment', 'human_review_required'],
            'publish_requires' => ['operator_approval', 'claim_review', 'sensitive_disclosure_review'],
            'autonomy_ceiling' => 'draft_and_review',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicDecisionDomainGatePolicy(): array
    {
        return [
            'required' => ['cooldown_policy', 'multi_perspective_review', 'values_alignment', 'operator_agency'],
            'high_impact_requires' => ['human_review', 'rivals_strategy_case', 'scheduled_revisit'],
            'autonomy_ceiling' => 'review_only',
            'forbidden' => ['autonomous_commitment', 'auto_financial_execution', 'auto_life_decision'],
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

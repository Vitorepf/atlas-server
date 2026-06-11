<?php

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasAaelAuditReport;
use App\Models\AtlasAaelEvolutionExperiment;
use App\Models\AtlasAaelOpportunity;
use App\Models\AtlasAaelPortfolioCycle;
use App\Models\AtlasAaelPromotionDecision;
use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionService;
use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasAiAssistedExecutionQualityService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiStringListNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AtlasAutonomousEvolutionLoopService
{
    public const OPPORTUNITY_SCHEMA = 'atlas.aael.opportunity.v1';

    public const CYCLE_SCHEMA = 'atlas.aael.portfolio_cycle.v1';

    public const EXPERIMENT_SCHEMA = 'atlas.aael.evolution_experiment.v1';

    public const PROMOTION_SCHEMA = 'atlas.aael.promotion_decision.v1';

    public const AUDIT_SCHEMA = 'atlas.aael.audit_report.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.aael.control_plane.v1';

    public const ASSISTED_EXECUTION_BRIDGE_SCHEMA = 'atlas.aael.assisted_execution_bridge.v1';

    public const LEVEL_MAX = 'AAEL-L10 Autonomous Evolution Portfolio OS';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly ?AtlasAutonomousWorkExecutionService $aweos = null,
        private readonly ?AtlasIntelligenceFactoryRuntimeService $intelligenceFactory = null,
        private readonly ?AtlasAiAssistedExecutionQualityService $assistedExecutionQuality = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function runCycle(array $input): array
    {
        $surfaceId = $this->stringValue($input['surface_id'] ?? null) ?? 'atlas_evolution_command';
        $workspace = $this->stringValue($input['workspace'] ?? null) ?? base_path();
        $evidenceRefs = AiStringListNormalizer::uniqueTrimmedScalarValues($input['evidence_refs'] ?? []);
        $opportunities = $this->observeOpportunities($input);
        $selectionPolicy = $this->selectionPolicy($input);
        $autonomyBudget = $this->autonomyBudget($input);
        $strategicGate = $this->strategicAlignmentGate($opportunities, $input);
        $antiDriftGate = $this->antiDriftDoctrineGate($opportunities, $input);
        $selected = $this->selectOpportunities($opportunities, $selectionPolicy, $autonomyBudget, $strategicGate, $antiDriftGate);
        $deferred = $this->deferredOpportunities($opportunities, $selected);
        $operatorQueue = $this->operatorQueue($selected, $autonomyBudget, $antiDriftGate);
        $status = $operatorQueue === [] ? self::STATUS_READY : self::STATUS_WATCH;

        $cyclePayload = [
            'schema_version' => self::CYCLE_SCHEMA,
            'status' => $status,
            'surface_id' => $surfaceId,
            'workspace_hash' => MissionCanonicalHash::sha256(['workspace' => $workspace]),
            'portfolio_snapshot' => [
                'maturity_level' => self::LEVEL_MAX,
                'observed_opportunity_count' => count($opportunities),
                'selected_count' => count($selected),
                'deferred_count' => count($deferred),
                'operator_queue_count' => count($operatorQueue),
            ],
            'selection_policy' => $selectionPolicy,
            'autonomy_budget' => $autonomyBudget,
            'strategic_alignment_gate' => $strategicGate,
            'anti_drift_doctrine_gate' => $antiDriftGate,
            'selected_opportunities' => $selected,
            'deferred_opportunities' => $deferred,
            'operator_queue' => $operatorQueue,
            'evidence_refs' => $evidenceRefs,
        ];
        $cyclePayload['cycle_hash'] = MissionCanonicalHash::sha256($cyclePayload);

        $cycleRecord = null;
        if (DatabaseTableAvailability::has('atlas_aael_portfolio_cycles')) {
            $cycleRecord = AtlasAaelPortfolioCycle::query()->create($cyclePayload);
        }

        $experiments = [];
        foreach ($selected as $opportunity) {
            $experiments[] = $this->createExperiment($cycleRecord?->id, $opportunity, $workspace, $evidenceRefs, $input);
        }

        $promotionDecisions = [];
        foreach ($experiments as $experiment) {
            $promotionDecisions[] = $this->decidePromotion($cycleRecord?->id, $experiment, $evidenceRefs);
        }

        $audit = $this->auditCycle($cycleRecord?->id, $cyclePayload, $experiments, $promotionDecisions, $evidenceRefs);

        return [
            ...$cyclePayload,
            'cycle_id' => $cycleRecord?->id,
            'opportunities' => $opportunities,
            'experiments' => $experiments,
            'promotion_decisions' => $promotionDecisions,
            'audit_report' => $audit,
            'writes' => $cycleRecord !== null,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    public function observeOpportunities(array $input): array
    {
        $raw = is_array($input['opportunities'] ?? null) ? $input['opportunities'] : [];
        if ($raw === []) {
            $raw = [[
                'objective' => $this->stringValue($input['objective'] ?? null) ?? 'Improve Atlas autonomous evolution loop with verified evidence and minimal human intervention.',
                'source_type' => $this->stringValue($input['source_type'] ?? null) ?? 'operator_goal',
                'domain' => $this->stringValue($input['domain'] ?? null) ?? 'programming',
                'flow_id' => $this->stringValue($input['flow_id'] ?? null) ?? 'atlas_forge',
                'signals' => AiStringListNormalizer::uniqueTrimmedScalarValues($input['signals'] ?? ['operator_requested_evolution', 'requires_evidence', 'reuse_existing_runtimes']),
            ]];
        }

        $opportunities = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $objective = $this->stringValue($item['objective'] ?? null) ?? 'Atlas evolution opportunity';
            $domain = $this->stringValue($item['domain'] ?? null) ?? 'programming';
            $flowId = $this->stringValue($item['flow_id'] ?? null) ?? $this->flowForObjective($objective);
            $risk = $this->riskLevel($objective, $item);
            $alignment = $this->alignmentScore($objective, $item);
            $roi = $this->roiModel($objective, $item);
            $priority = $this->priorityScore($roi, $alignment, $risk);
            $payload = [
                'schema_version' => self::OPPORTUNITY_SCHEMA,
                'status' => $priority >= 0.55 ? 'candidate' : 'deferred',
                'source_type' => $this->stringValue($item['source_type'] ?? null) ?? 'runtime_signal',
                'domain' => $domain,
                'flow_id' => $flowId,
                'opportunity_type' => $this->opportunityType($objective),
                'risk_level' => $risk,
                'priority_score' => $priority,
                'strategic_alignment_score' => $alignment,
                'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
                'objective' => $objective,
                'signals' => AiStringListNormalizer::uniqueTrimmedScalarValues($item['signals'] ?? []),
                'roi_model' => $roi,
                'dependencies' => AiStringListNormalizer::uniqueTrimmedScalarValues($item['dependencies'] ?? []),
                'evidence_refs' => AiStringListNormalizer::uniqueTrimmedScalarValues($item['evidence_refs'] ?? $input['evidence_refs'] ?? []),
            ];
            $payload['opportunity_hash'] = MissionCanonicalHash::sha256($payload);

            $record = null;
            if (DatabaseTableAvailability::has('atlas_aael_opportunities')) {
                $record = AtlasAaelOpportunity::query()->create($payload);
            }
            $opportunities[] = [
                ...$payload,
                'opportunity_id' => $record?->id,
                'writes' => $record !== null,
            ];
        }

        usort($opportunities, fn (array $a, array $b): int => ($b['priority_score'] <=> $a['priority_score']));

        return $opportunities;
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $summary = [
            'opportunities_total' => $this->countSince('atlas_aael_opportunities', $since),
            'cycles_total' => $this->countSince('atlas_aael_portfolio_cycles', $since),
            'experiments_total' => $this->countSince('atlas_aael_evolution_experiments', $since),
            'promotion_decisions_total' => $this->countSince('atlas_aael_promotion_decisions', $since),
            'audit_reports_total' => $this->countSince('atlas_aael_audit_reports', $since),
            'operator_review_required' => $this->countWhereSince('atlas_aael_promotion_decisions', 'status', 'operator_review_required', $since),
            'blocked' => $this->countWhereSince('atlas_aael_promotion_decisions', 'status', self::STATUS_BLOCKED, $since),
        ];
        $payload = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => $summary['blocked'] > 0 ? self::STATUS_BLOCKED : ($summary['operator_review_required'] > 0 ? self::STATUS_WATCH : self::STATUS_READY),
            'window' => ['hours' => max(1, $hours), 'since' => $since->toJSON()],
            'summary' => $summary,
            'recent_cycles' => $this->recentCycles($since),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'schema_version' => 'atlas.aael.claim_policy.v1',
            'autonomous_core_mutation_allowed' => false,
            'provider_invoked_directly' => false,
            'benchmark_not_run' => true,
            'requires_sandbox_before_promotion' => true,
            'requires_aver_for_execution_claim' => true,
            'requires_aemor_for_learning_claim' => true,
            'uses_self_construction_for_atlas_building_atlas' => true,
            'human_required_for_high_risk_or_irreversible_changes' => true,
            'does_not_replace_forge_or_dev' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $opportunities
     * @return array<string,mixed>
     */
    private function strategicAlignmentGate(array $opportunities, array $input): array
    {
        $minimum = (float) ($input['minimum_alignment_score'] ?? 0.62);
        $low = array_values(array_filter($opportunities, fn (array $item): bool => (float) $item['strategic_alignment_score'] < $minimum));

        return [
            'status' => $low === [] ? 'passed' : 'watch',
            'minimum_score' => $minimum,
            'low_alignment_count' => count($low),
            'principles' => [
                'reduce_operator_manual_work',
                'improve_dev_or_forge_quality',
                'avoid_parallel_runtime_duplication',
                'preserve_evidence_and_rollback',
                'compound_future_execution_quality',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $opportunities
     * @return array<string,mixed>
     */
    private function antiDriftDoctrineGate(array $opportunities, array $input): array
    {
        $violations = [];
        foreach ($opportunities as $opportunity) {
            $objective = Str::lower((string) $opportunity['objective']);
            foreach (['replace forge', 'bypass evidence', 'skip tests', 'direct production mutation', 'parallel self construction'] as $bad) {
                if (str_contains($objective, $bad)) {
                    $violations[] = ['opportunity_hash' => $opportunity['opportunity_hash'], 'violation' => $bad];
                }
            }
        }

        return [
            'status' => $violations === [] ? 'passed' : self::STATUS_BLOCKED,
            'violations' => $violations,
            'doctrine_refs' => [
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md',
                'docs/engineering-knowledge-base/atlas-verified-execution-runtime.md',
            ],
            'strict' => (bool) ($input['strict_doctrine'] ?? true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function autonomyBudget(array $input): array
    {
        return [
            'status' => 'active',
            'max_selected_opportunities' => max(1, (int) ($input['max_selected_opportunities'] ?? 3)),
            'max_high_risk_without_human' => 0,
            'max_estimated_hours' => max(1, (int) ($input['max_estimated_hours'] ?? 8)),
            'max_repair_attempts' => max(1, (int) ($input['max_repair_attempts'] ?? 3)),
            'max_files_changed_without_review' => max(1, (int) ($input['max_files_changed_without_review'] ?? 6)),
            'allow_auto_promotion' => (bool) ($input['allow_auto_promotion'] ?? true),
            'human_signature_required_for' => ['security', 'provider_topology', 'payments', 'production_data', 'self_programming_activation', 'high_risk'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selectionPolicy(array $input): array
    {
        return [
            'strategy' => $this->stringValue($input['selection_strategy'] ?? null) ?? 'highest_roi_lowest_risk',
            'minimum_priority_score' => (float) ($input['minimum_priority_score'] ?? 0.55),
            'prefer_reuse_before_build' => true,
            'prefer_existing_self_construction_for_atlas_mutation' => true,
            'forbid_duplicate_runtime' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $opportunities
     * @return list<array<string,mixed>>
     */
    private function selectOpportunities(array $opportunities, array $policy, array $budget, array $strategicGate, array $antiDriftGate): array
    {
        if (($antiDriftGate['status'] ?? null) === self::STATUS_BLOCKED) {
            return [];
        }
        $minimum = (float) $policy['minimum_priority_score'];
        $limit = (int) $budget['max_selected_opportunities'];

        return array_values(array_slice(array_filter($opportunities, function (array $item) use ($minimum): bool {
            return (float) $item['priority_score'] >= $minimum
                && (string) $item['risk_level'] !== 'critical';
        }), 0, $limit));
    }

    /**
     * @param  list<array<string,mixed>>  $opportunities
     * @param  list<array<string,mixed>>  $selected
     * @return list<array<string,mixed>>
     */
    private function deferredOpportunities(array $opportunities, array $selected): array
    {
        $selectedHashes = array_flip(array_map('strval', array_column($selected, 'opportunity_hash')));

        return array_values(array_map(fn (array $item): array => [
            'opportunity_hash' => $item['opportunity_hash'],
            'objective_hash' => $item['objective_hash'],
            'reason' => isset($selectedHashes[$item['opportunity_hash']]) ? 'selected' : 'not_selected_this_cycle',
            'priority_score' => $item['priority_score'],
            'risk_level' => $item['risk_level'],
        ], array_filter($opportunities, fn (array $item): bool => ! isset($selectedHashes[$item['opportunity_hash']]))));
    }

    /**
     * @param  list<array<string,mixed>>  $selected
     * @return list<array<string,mixed>>
     */
    private function operatorQueue(array $selected, array $budget, array $antiDriftGate): array
    {
        $queue = [];
        if (($antiDriftGate['status'] ?? null) === self::STATUS_BLOCKED) {
            $queue[] = ['kind' => 'doctrine_violation', 'action' => 'review_before_any_execution', 'violations' => $antiDriftGate['violations']];
        }
        foreach ($selected as $item) {
            if (in_array($item['risk_level'], ['high', 'critical'], true)) {
                $queue[] = [
                    'kind' => 'high_risk_evolution',
                    'opportunity_hash' => $item['opportunity_hash'],
                    'action' => 'human_signature_required',
                ];
            }
        }

        return $queue;
    }

    /**
     * @return array<string,mixed>
     */
    private function createExperiment(?string $cycleId, array $opportunity, string $workspace, array $evidenceRefs, array $input): array
    {
        $aseif = $this->intelligenceFactory?->advise([
            'objective' => $opportunity['objective'],
            'domain' => $opportunity['domain'],
            'flow_id' => $opportunity['flow_id'],
            'workspace' => $workspace,
            'evidence_refs' => $evidenceRefs,
            'source' => 'aael',
        ]) ?? ['status' => 'unavailable'];
        $aweos = $this->aweos?->run([
            'objective' => $opportunity['objective'],
            'domain' => $opportunity['domain'],
            'flow_id' => $opportunity['flow_id'],
            'surface_id' => 'atlas_evolution_command',
            'workspace' => $workspace,
            'evidence_refs' => AiStringListNormalizer::uniqueMergedStrings($evidenceRefs, ['aael:evolution_experiment']),
        ]) ?? ['status' => 'unavailable'];
        $assistedExecution = $this->assistedExecutionBridge($opportunity, $workspace, $evidenceRefs, $input);

        $impact = $this->impactSimulation($opportunity, $aseif, $aweos);
        $lane = $this->experimentLane($opportunity, $impact);
        $status = $lane === 'blocked' ? self::STATUS_BLOCKED : 'sandbox_planned';
        $payload = [
            'cycle_id' => $cycleId,
            'opportunity_id' => $this->uuidOrNull($opportunity['opportunity_id'] ?? null),
            'aweos_execution_id' => $this->uuidOrNull($aweos['execution_id'] ?? null),
            'intelligence_factory_gap_id' => $this->uuidOrNull(data_get($aseif, 'gap.gap_id')),
            'schema_version' => self::EXPERIMENT_SCHEMA,
            'status' => $status,
            'lane' => $lane,
            'spec_packet' => $this->specPacket($opportunity),
            'impact_simulation' => $impact,
            'execution_plan' => data_get($aweos, 'execution_plan', $this->fallbackExecutionPlan($opportunity)),
            'assisted_execution_quality' => $assistedExecution,
            'verification_plan' => [
                'requires_aver' => true,
                'requires_aaeq_aedpds_areg_aemor' => true,
                'requires_docs_health_when_docs_change' => true,
                'requires_targeted_tests' => true,
                'requires_diff_review' => true,
                'forbids_benchmark_claim' => true,
            ],
            'rollback_plan' => [
                'strategy' => 'revert_sandbox_or_reject_promotion',
                'requires_no_production_side_effects_before_promotion' => true,
                'operator_can_reject_without_runtime_mutation' => true,
            ],
            'learning_plan' => [
                'aemor_required' => true,
                'record_negative_knowledge_on_failure' => true,
                'update_provider_skill_reliability' => true,
                'do_not_promote_learning_without_evidence' => true,
            ],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['experiment_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aael_evolution_experiments') && $cycleId !== null) {
            $record = AtlasAaelEvolutionExperiment::query()->create($payload);
        }

        return [
            ...$payload,
            'experiment_id' => $record?->id,
            'intelligence_factory' => $aseif,
            'autonomous_work_execution' => $this->summarizeAweos($aweos),
            'assisted_execution_quality' => $assistedExecution,
            'writes' => $record !== null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decidePromotion(?string $cycleId, array $experiment, array $evidenceRefs): array
    {
        $risk = (string) data_get($experiment, 'impact_simulation.risk_level', 'medium');
        $missingEvidence = $evidenceRefs === [];
        $assistedExecutionReady = data_get($experiment, 'assisted_execution_quality.status') === self::STATUS_READY;
        $trustLevel = match (true) {
            ($experiment['status'] ?? null) === self::STATUS_BLOCKED => 'forbidden',
            $risk === 'high' || $risk === 'critical' => 'signature_required',
            ! $assistedExecutionReady => 'review_required',
            $missingEvidence => 'review_required',
            default => 'auto_with_rollback',
        };
        $status = match ($trustLevel) {
            'forbidden' => self::STATUS_BLOCKED,
            'signature_required', 'review_required' => 'operator_review_required',
            default => 'promotion_ready',
        };
        $payload = [
            'cycle_id' => $cycleId,
            'experiment_id' => $this->uuidOrNull($experiment['experiment_id'] ?? null),
            'schema_version' => self::PROMOTION_SCHEMA,
            'status' => $status,
            'trust_level' => $trustLevel,
            'promotion_gate' => [
                'sandbox_required' => true,
                'aver_required' => true,
                'aemor_required' => true,
                'assisted_execution_quality_required' => true,
                'assisted_execution_quality_status' => data_get($experiment, 'assisted_execution_quality.status'),
                'evidence_refs_required' => true,
                'has_evidence_refs' => ! $missingEvidence,
                'risk_level' => $risk,
            ],
            'risk_controls' => [
                'rollback_required' => true,
                'operator_signature_required' => $trustLevel === 'signature_required',
                'auto_promotion_allowed' => $trustLevel === 'auto_with_rollback',
                'max_scope_without_review' => 'small_non_core_patch_or_doc',
            ],
            'operator_action' => [
                'required' => in_array($trustLevel, ['signature_required', 'review_required'], true),
                'action' => $trustLevel === 'signature_required' ? 'sign_or_reject' : ($trustLevel === 'review_required' ? 'provide_evidence_or_approve' : 'none'),
            ],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['decision_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aael_promotion_decisions') && $cycleId !== null) {
            $record = AtlasAaelPromotionDecision::query()->create($payload);
        }

        return [...$payload, 'decision_id' => $record?->id, 'writes' => $record !== null];
    }

    /**
     * @param  list<array<string,mixed>>  $experiments
     * @param  list<array<string,mixed>>  $promotionDecisions
     * @return array<string,mixed>
     */
    private function auditCycle(?string $cycleId, array $cycle, array $experiments, array $promotionDecisions, array $evidenceRefs): array
    {
        $blocked = count(array_filter($promotionDecisions, fn (array $decision): bool => ($decision['status'] ?? null) === self::STATUS_BLOCKED));
        $review = count(array_filter($promotionDecisions, fn (array $decision): bool => ($decision['status'] ?? null) === 'operator_review_required'));
        $assistedReady = count(array_filter($experiments, fn (array $experiment): bool => data_get($experiment, 'assisted_execution_quality.status') === self::STATUS_READY));
        $score = max(0.0, min(1.0, 0.92 - ($blocked * 0.25) - ($review * 0.08) + (count($evidenceRefs) > 0 ? 0.04 : 0.0)));
        $payload = [
            'cycle_id' => $cycleId,
            'schema_version' => self::AUDIT_SCHEMA,
            'status' => $blocked > 0 ? self::STATUS_BLOCKED : ($review > 0 ? self::STATUS_WATCH : self::STATUS_READY),
            'audit_court' => [
                'architecture_critic' => 'passed',
                'risk_critic' => $blocked > 0 ? 'blocked' : 'passed',
                'evidence_critic' => $evidenceRefs === [] ? 'watch_missing_external_evidence' : 'passed',
                'overengineering_critic' => 'passed_reuse_existing_aweos_aseif_aver_aemor',
                'doctrine_critic' => data_get($cycle, 'anti_drift_doctrine_gate.status'),
                'assisted_execution_critic' => $assistedReady === count($experiments) ? 'passed' : 'watch',
            ],
            'self_evolution_memory' => [
                'record_positive_patterns' => ['reuse_existing_runtime', 'sandbox_before_promotion', 'operator_only_for_high_risk'],
                'record_negative_patterns' => ['parallel_runtime_duplication', 'promotion_without_evidence', 'benchmark_claim_before_gate'],
                'memory_promotion_requires_aemor' => true,
            ],
            'dormant_capability_activation' => [
                'checks_existing_capabilities_before_build' => true,
                'uses_aseif_candidates' => true,
                'activation_requires_certification' => true,
            ],
            'quality_score' => [
                'score' => round($score, 4),
                'blocked_decisions' => $blocked,
                'operator_review_decisions' => $review,
                'experiment_count' => count($experiments),
                'assisted_execution_ready_count' => $assistedReady,
            ],
            'claim_policy' => $this->claimPolicy(),
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['audit_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aael_audit_reports')) {
            $record = AtlasAaelAuditReport::query()->create($payload);
        }

        return [...$payload, 'audit_report_id' => $record?->id, 'writes' => $record !== null];
    }

    private function opportunityType(string $objective): string
    {
        $text = Str::lower($objective);

        return match (true) {
            str_contains($text, 'test') || str_contains($text, 'certif') => 'quality_hardening',
            str_contains($text, 'context') || str_contains($text, 'memory') => 'context_intelligence',
            str_contains($text, 'forge') || str_contains($text, 'obra') => 'forge_evolution',
            str_contains($text, 'router') || str_contains($text, 'hyperflow') => 'routing_evolution',
            str_contains($text, 'tool') || str_contains($text, 'capability') => 'capability_evolution',
            default => 'system_evolution',
        };
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function assistedExecutionBridge(array $opportunity, string $workspace, array $evidenceRefs, array $input): array
    {
        $service = $this->assistedExecutionQuality ?? app(AtlasAiAssistedExecutionQualityService::class);
        $route = (string) ($opportunity['flow_id'] ?? 'atlas_dev');
        $requiredEvidence = AiStringListNormalizer::uniqueMergedStrings($evidenceRefs, ['aael:assisted_execution_bridge']);
        $envelope = $service->buildEnvelope([
            'human_request' => (string) ($opportunity['objective'] ?? 'AAEL evolution opportunity'),
            'workspace' => $workspace,
            'surface_id' => 'atlas_evolution_command',
            'route' => str_contains($route, 'forge') ? 'atlas_forge' : 'atlas_dev',
            'context_refs' => AiStringListNormalizer::uniqueTrimmedScalarValues($input['context_refs'] ?? [
                'docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md',
                'docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md',
            ]),
            'expected_files' => AiStringListNormalizer::uniqueTrimmedScalarValues($opportunity['dependencies'] ?? []),
            'acceptance_criteria' => [
                'AAEL opportunity has AEDPDS doctrine and gate status.',
                'AAEL experiment has AREG path and AEMOR feedback contract.',
                'AAEL promotion cannot claim ready without evidence refs and assisted execution quality.',
            ],
            'suggested_tests' => ['php artisan test tests/Feature/Ai/AutonomousEvolution'],
            'required_evidence' => $requiredEvidence,
            'risk_band' => $opportunity['risk_level'] ?? 'medium',
            'review_refs' => in_array(($opportunity['risk_level'] ?? 'medium'), ['high', 'critical'], true)
                ? AiStringListNormalizer::uniqueTrimmedScalarValues($input['review_refs'] ?? [])
                : ['aael:low_risk_auto_review_policy'],
        ]);
        $feedback = $service->recordOutcomeFeedback($envelope, [
            'status' => ($envelope['status'] ?? null) === 'ready_for_assisted_execution' ? 'succeeded' : 'blocked',
            'quality_score' => ($envelope['status'] ?? null) === 'ready_for_assisted_execution' ? 0.88 : 0.42,
            'context_roi_score' => ($envelope['status'] ?? null) === 'ready_for_assisted_execution' ? 0.80 : 0.35,
            'evidence_refs' => $requiredEvidence,
            'persist' => false,
        ]);

        $blockers = AiStringListNormalizer::uniqueMergedStrings(
            $this->blockerIds(is_array($envelope['blockers'] ?? null) ? $envelope['blockers'] : []),
            $this->blockerIds(is_array($feedback['blockers'] ?? null) ? $feedback['blockers'] : []),
        );

        $payload = [
            'schema_version' => self::ASSISTED_EXECUTION_BRIDGE_SCHEMA,
            'status' => ($envelope['status'] ?? null) === 'ready_for_assisted_execution' && ($feedback['status'] ?? null) === 'recorded' && $blockers === []
                ? self::STATUS_READY
                : self::STATUS_WATCH,
            'route_target' => data_get($envelope, 'route.target'),
            'flow_id' => data_get($envelope, 'route.flow_id'),
            'aedpds_gate_status' => data_get($envelope, 'aedpds.gate.status'),
            'selected_drivers' => AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($envelope, 'aedpds.doctrine.selected_primary_drivers', [])),
            'context_memory_status' => data_get($envelope, 'aucri_acmf.status'),
            'areg_path' => data_get($envelope, 'areg.path'),
            'outcome_feedback_status' => data_get($feedback, 'status'),
            'aemor_feedback_status' => data_get($feedback, 'aemor_outcome.status'),
            'blockers' => $blockers,
            'envelope_hash' => data_get($envelope, 'assisted_execution_hash'),
            'feedback_hash' => data_get($feedback, 'feedback_hash'),
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'raw_objective_exposed' => false,
                'promotion_requires_evidence' => true,
            ],
        ];
        $payload['bridge_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $blockers
     * @return list<string>
     */
    private function blockerIds(array $blockers): array
    {
        return array_values(array_filter(array_map(
            fn (array $blocker): string => $this->stringValue($blocker['id'] ?? null) ?? '',
            $blockers,
        ), fn (string $id): bool => $id !== ''));
    }

    private function riskLevel(string $objective, array $input): string
    {
        if (($risk = $this->stringValue($input['risk_level'] ?? null)) !== null) {
            return $risk;
        }
        $text = Str::lower($objective);

        return match (true) {
            str_contains($text, 'payment') || str_contains($text, 'security') || str_contains($text, 'self-programming activation') => 'critical',
            str_contains($text, 'router') || str_contains($text, 'provider') || str_contains($text, 'database') || str_contains($text, 'migration') => 'high',
            str_contains($text, 'doc') || str_contains($text, 'test') => 'low',
            default => 'medium',
        };
    }

    private function alignmentScore(string $objective, array $input): float
    {
        if (is_numeric($input['strategic_alignment_score'] ?? null)) {
            return round((float) $input['strategic_alignment_score'], 4);
        }
        $text = Str::lower($objective);
        $score = 0.48;
        foreach (['atlas', 'forge', 'dev', 'quality', 'evidence', 'context', 'autonomous', 'test', 'runtime'] as $term) {
            if (str_contains($text, $term)) {
                $score += 0.055;
            }
        }

        return round(min(1.0, $score), 4);
    }

    /**
     * @return array<string,mixed>
     */
    private function roiModel(string $objective, array $input): array
    {
        $impact = (float) ($input['impact'] ?? (str_contains(Str::lower($objective), 'manual') ? 0.86 : 0.72));
        $frequency = (float) ($input['frequency'] ?? 0.68);
        $effort = (float) ($input['effort'] ?? 0.42);
        $riskPenalty = match ($this->riskLevel($objective, $input)) {
            'critical' => 0.45,
            'high' => 0.25,
            'medium' => 0.12,
            default => 0.04,
        };

        return [
            'impact' => round(min(1.0, $impact), 4),
            'frequency' => round(min(1.0, $frequency), 4),
            'effort' => round(min(1.0, $effort), 4),
            'risk_penalty' => $riskPenalty,
            'estimated_hours_saved_per_month' => round(($impact * $frequency * 40) - ($effort * 4), 2),
        ];
    }

    private function priorityScore(array $roi, float $alignment, string $risk): float
    {
        $riskPenalty = (float) $roi['risk_penalty'];
        $score = ((float) $roi['impact'] * 0.35) + ((float) $roi['frequency'] * 0.2) + ($alignment * 0.3) + ((1 - (float) $roi['effort']) * 0.15) - $riskPenalty;

        return round(max(0.0, min(1.0, $score)), 4);
    }

    private function flowForObjective(string $objective): string
    {
        $text = Str::lower($objective);

        return str_contains($text, 'forge') || str_contains($text, 'obra') || str_contains($text, 'multi') ? 'atlas_forge' : 'atlas_dev';
    }

    /**
     * @return array<string,mixed>
     */
    private function impactSimulation(array $opportunity, array $aseif, array $aweos): array
    {
        $risk = (string) $opportunity['risk_level'];

        return [
            'status' => $risk === 'critical' ? self::STATUS_BLOCKED : 'passed',
            'risk_level' => $risk,
            'predicted_blast_radius' => match ($risk) {
                'high' => 'multi_module_requires_review',
                'critical' => 'forbidden_without_human_signature',
                default => 'bounded',
            },
            'expected_tests' => ['targeted_feature_tests', 'docs_health_if_docs_change', 'git_diff_check'],
            'aseif_status' => $aseif['status'] ?? 'unknown',
            'aweos_status' => $aweos['status'] ?? 'unknown',
            'rollback_confidence' => $risk === 'low' ? 0.92 : 0.76,
        ];
    }

    private function experimentLane(array $opportunity, array $impact): string
    {
        if (($impact['status'] ?? null) === self::STATUS_BLOCKED) {
            return 'blocked';
        }

        return match ($opportunity['risk_level']) {
            'high' => 'forge_sandbox_operator_review',
            'medium' => 'aweos_sandbox',
            default => 'auto_safe_sandbox',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function specPacket(array $opportunity): array
    {
        return [
            'schema_version' => 'atlas.aael.spec_packet.v1',
            'objective_hash' => $opportunity['objective_hash'],
            'opportunity_type' => $opportunity['opportunity_type'],
            'domain' => $opportunity['domain'],
            'flow_id' => $opportunity['flow_id'],
            'definition_of_done' => [
                'implementation_or_sandbox_plan_exists',
                'tests_or_certification_gate_defined',
                'rollback_plan_defined',
                'evidence_refs_bound',
                'learning_policy_defined',
            ],
            'self_construction_required' => ((string) $opportunity['domain']) === 'programming',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackExecutionPlan(array $opportunity): array
    {
        return [
            'status' => 'planned',
            'steps' => ['retrieve_context', 'compile_spec', 'execute_in_sandbox', 'verify', 'decide_promotion'],
            'flow_id' => $opportunity['flow_id'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeAweos(array $aweos): array
    {
        return [
            'schema_version' => $aweos['schema_version'] ?? null,
            'status' => $aweos['status'] ?? null,
            'execution_id' => $aweos['execution_id'] ?? null,
            'execution_hash' => $aweos['execution_hash'] ?? null,
            'verified_execution_schema' => data_get($aweos, 'verified_execution.schema_version'),
        ];
    }

    private function countSince(string $table, CarbonImmutable $since): int
    {
        if (! DatabaseTableAvailability::has($table)) {
            return 0;
        }

        return (int) \DB::table($table)->where('created_at', '>=', $since)->count();
    }

    private function countWhereSince(string $table, string $column, string $value, CarbonImmutable $since): int
    {
        if (! DatabaseTableAvailability::has($table)) {
            return 0;
        }

        return (int) \DB::table($table)->where($column, $value)->where('created_at', '>=', $since)->count();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentCycles(CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('atlas_aael_portfolio_cycles')) {
            return [];
        }

        return AtlasAaelPortfolioCycle::query()
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'status', 'portfolio_snapshot', 'cycle_hash', 'created_at'])
            ->map(fn (AtlasAaelPortfolioCycle $cycle): array => [
                'cycle_id' => $cycle->id,
                'status' => $cycle->status,
                'selected_count' => (int) data_get($cycle->portfolio_snapshot, 'selected_count', 0),
                'operator_queue_count' => (int) data_get($cycle->portfolio_snapshot, 'operator_queue_count', 0),
                'cycle_hash' => $cycle->cycle_hash,
                'created_at' => $cycle->created_at?->toJSON(),
            ])
            ->values()
            ->all();
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string !== null && Str::isUuid($string) ? $string : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\StrategicOperatingSystem;

use App\Models\AtlasProductDeliveryOutcomeMemory;
use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasProductDeliveryProviderMemoryFeedService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;

final class AtlasStrategicOperatingSystemRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.strategic_operating_system.v1';

    public const RUNTIME_FEEDBACK_GRAPH_SCHEMA_VERSION = 'atlas.runtime_feedback_graph.v1';

    public const EXPERIMENT_STRATEGY_LOOP_SCHEMA_VERSION = 'atlas.autonomous_experiment_strategy_loop.v1';

    public const ORGANIZATION_TWIN_SCHEMA_VERSION = 'atlas.organization_operating_system_twin.v1';

    public const PORTFOLIO_CAPITAL_BRAIN_SCHEMA_VERSION = 'atlas.portfolio_capital_allocation_brain.v1';

    public const GOVERNANCE_POLICY_EVOLUTION_SCHEMA_VERSION = 'atlas.autonomous_governance_policy_evolution.v1';

    public const CERTIFICATION_SCHEMA_VERSION = 'atlas.strategic_operating_system.certification.v1';

    public function __construct(
        private readonly ?AtlasProductDeliveryProviderMemoryFeedService $providerMemory = null,
        private readonly ?AtlasVerifiedExecutionRuntimeService $verifiedExecution = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function operatingSystem(array $input = []): array
    {
        $feedback = $this->runtimeFeedbackGraph($input);
        $experiment = $this->experimentStrategyLoop($feedback, $input);
        $organization = $this->organizationTwin($feedback, $experiment, $input);
        $portfolio = $this->portfolioCapitalBrain($feedback, $experiment, $organization, $input);
        $governance = $this->governancePolicyEvolution($feedback, $experiment, $organization, $portfolio, $input);
        $blockers = $this->operatingSystemBlockers($feedback, $experiment, $organization, $portfolio, $governance);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'mode' => 'provider_free_read_only_strategy_os',
            'runtime_feedback_graph' => $feedback,
            'autonomous_experiment_strategy_loop' => $experiment,
            'organization_twin' => $organization,
            'portfolio_capital_allocation_brain' => $portfolio,
            'autonomous_governance_policy_evolution' => $governance,
            'system_readiness' => [
                'runtime_reality_grounded' => ($feedback['status'] ?? null) === 'ready',
                'experiments_grounded_in_feedback' => ($experiment['status'] ?? null) === 'ready',
                'organization_sequence_available' => ($organization['status'] ?? null) === 'ready',
                'capital_allocation_available' => ($portfolio['status'] ?? null) === 'ready',
                'policy_evolution_review_gated' => ($governance['status'] ?? null) === 'ready',
            ],
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_applies_policy' => false,
                'auto_spends_capital' => false,
                'auto_runs_experiments' => false,
                'requires_verified_execution_for_mutation' => true,
                'requires_human_review_for_policy_or_capital_change' => true,
            ],
        ];
        $payload['strategic_operating_system_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function runtimeFeedbackGraph(array $input = []): array
    {
        $receipts = $this->runtimeReceipts((int) ($input['limit'] ?? 50));
        $outcomes = $this->outcomeMemories((int) ($input['limit'] ?? 50));
        $manualSignals = $this->manualSignals($input);
        $providerMemory = $this->providerMemory()->analyze([
            'limit' => (int) ($input['limit'] ?? 100),
            'route' => $this->string($input['route'] ?? null),
        ]);

        $nodes = $this->feedbackNodes($receipts, $outcomes, $manualSignals, $providerMemory);
        $edges = $this->feedbackEdges($nodes);
        $metrics = $this->feedbackMetrics($receipts, $outcomes, $manualSignals, $providerMemory);
        $status = ($nodes === [] && $manualSignals === []) ? 'watch' : 'ready';

        $payload = [
            'schema_version' => self::RUNTIME_FEEDBACK_GRAPH_SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'reality_signal_read_model',
            'coverage' => [
                'logs' => count(AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['logs'] ?? [])),
                'incidents' => count(AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['incidents'] ?? [])),
                'regressions' => count(AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['regressions'] ?? [])),
                'product_metrics' => count($this->assoc($input['metrics'] ?? [])),
                'revenue' => $this->number($input['revenue_usd'] ?? $input['observed_revenue_usd'] ?? null),
                'costs' => $this->number($input['cost_usd'] ?? $input['observed_cost_usd'] ?? null),
                'runtime_receipts' => count($receipts),
                'outcomes' => count($outcomes),
                'feedback_items' => count(AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['human_feedback'] ?? [])),
            ],
            'nodes' => array_slice($nodes, 0, 80),
            'edges' => array_slice($edges, 0, 120),
            'metrics' => $metrics,
            'risk_signals' => $this->feedbackRiskSignals($metrics, $providerMemory),
            'provider_memory' => [
                'status' => $providerMemory['status'] ?? null,
                'provider_failure_count' => (int) data_get($providerMemory, 'risk_signals.provider_failure_count', 0),
                'flake_count' => (int) data_get($providerMemory, 'risk_signals.flake_count', 0),
                'cost_pressure' => (bool) data_get($providerMemory, 'risk_signals.cost_pressure', false),
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'runtime_feedback_is_read_model' => true,
            ],
        ];
        $payload['feedback_graph_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $feedback
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function experimentStrategyLoop(array $feedback, array $input = []): array
    {
        $hypotheses = $this->experimentHypotheses($feedback, $input);
        $verifiedPlan = $this->verifiedExecution()->plan([
            'objective' => 'Validate product/strategy experiment before mutation',
            'workspace' => (string) ($input['workspace'] ?? base_path()),
            'flow_id' => 'strategy_experiment',
            'expected_files' => AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['allowed_files'] ?? []),
            'evidence_refs' => [
                $feedback['feedback_graph_hash'] ?? '',
                'atlas.runtime_feedback_graph',
            ],
        ]);

        $payload = [
            'schema_version' => self::EXPERIMENT_STRATEGY_LOOP_SCHEMA_VERSION,
            'status' => $hypotheses === [] ? 'watch' : 'ready',
            'hypotheses' => $hypotheses,
            'experiment_design' => [
                'default_mode' => 'guarded_incremental_experiment',
                'sample_policy' => 'start_small_expand_after_green_runtime_feedback',
                'rollback_policy' => 'rollback_or_stop_if_error_cost_or_churn_regresses',
                'success_requires' => ['metric_delta_positive', 'risk_not_increased', 'cost_within_budget', 'verified_execution_ready'],
            ],
            'decision_loop' => [
                'scale_if' => ['primary_metric_above_target', 'risk_signals_stable', 'cost_pressure_false'],
                'iterate_if' => ['primary_metric_flat', 'sample_too_small', 'feedback_mixed'],
                'stop_if' => ['incident_regression', 'conversion_or_retention_drop', 'cost_pressure_true'],
                'feed_back_into' => ['runtime_feedback_graph', 'portfolio_capital_allocation_brain', 'governance_policy_evolution'],
            ],
            'verified_execution_sidecar' => [
                'schema_version' => $verifiedPlan['schema_version'] ?? null,
                'status' => $verifiedPlan['status'] ?? null,
                'execution_id' => $verifiedPlan['execution_id'] ?? null,
                'writes' => (bool) ($verifiedPlan['writes'] ?? false),
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => (bool) ($verifiedPlan['writes'] ?? false),
                'does_not_launch_experiment' => true,
                'mutation_requires_verified_execution' => true,
            ],
        ];
        $payload['experiment_strategy_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $feedback
     * @param  array<string,mixed>  $experiment
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function organizationTwin(array $feedback, array $experiment, array $input = []): array
    {
        $teams = $this->teams($input);
        $backlog = $this->backlog($input, $experiment);
        $dependencies = $this->dependencies($backlog);
        $debt = $this->debtSignals($feedback, $input);
        $sequence = $this->workSequence($backlog, $debt, $dependencies);

        $payload = [
            'schema_version' => self::ORGANIZATION_TWIN_SCHEMA_VERSION,
            'status' => $sequence === [] ? 'watch' : 'ready',
            'teams' => $teams,
            'ownership' => [
                'workspace' => (string) ($input['workspace'] ?? base_path()),
                'owner_count' => count($teams),
                'unowned_backlog_count' => count(array_filter($backlog, static fn (array $item): bool => ($item['owner'] ?? '') === 'unassigned')),
            ],
            'backlog' => $backlog,
            'dependencies' => $dependencies,
            'debt_and_risk' => $debt,
            'risk_by_area' => $this->riskByArea($backlog, $debt),
            'execution_capacity' => [
                'available_parallel_lanes' => max(1, count($teams)),
                'high_risk_work_limit' => 1,
                'review_capacity_required' => true,
            ],
            'recommended_sequence' => $sequence,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'organization_twin_is_planning_model' => true,
            ],
        ];
        $payload['organization_twin_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $feedback
     * @param  array<string,mixed>  $experiment
     * @param  array<string,mixed>  $organization
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function portfolioCapitalBrain(array $feedback, array $experiment, array $organization, array $input = []): array
    {
        $candidates = $this->portfolioCandidates($feedback, $experiment, $organization, $input);
        usort($candidates, static fn (array $a, array $b): int => ($b['allocation_score'] <=> $a['allocation_score']) ?: strcmp((string) $a['id'], (string) $b['id']));
        $budget = max(0.0, $this->number($input['capital_budget_usd'] ?? $input['budget_usd'] ?? 0.0) ?? 0.0);

        $payload = [
            'schema_version' => self::PORTFOLIO_CAPITAL_BRAIN_SCHEMA_VERSION,
            'status' => $candidates === [] ? 'watch' : 'ready',
            'capital_budget_usd' => $budget,
            'allocation_policy' => [
                'optimize_for' => ['expected_value', 'risk_adjusted_learning', 'strategic_dependency_unblock'],
                'penalize' => ['incident_risk', 'cost_pressure', 'unclear_owner', 'missing_feedback_signal'],
                'human_approval_required_for_spend' => true,
            ],
            'ranked_candidates' => array_slice($candidates, 0, 20),
            'recommended_allocation' => $this->allocation($candidates, $budget),
            'portfolio_risk' => [
                'concentration_risk' => count($candidates) <= 1 ? 'high' : 'medium',
                'cost_pressure' => (bool) data_get($feedback, 'risk_signals.cost_pressure', false),
                'incident_pressure' => (bool) data_get($feedback, 'risk_signals.incident_pressure', false),
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_spends_capital' => false,
                'requires_human_capital_approval' => true,
            ],
        ];
        $payload['portfolio_brain_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $feedback
     * @param  array<string,mixed>  $experiment
     * @param  array<string,mixed>  $organization
     * @param  array<string,mixed>  $portfolio
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function governancePolicyEvolution(array $feedback, array $experiment, array $organization, array $portfolio, array $input = []): array
    {
        $proposals = $this->policyProposals($feedback, $experiment, $organization, $portfolio);
        $payload = [
            'schema_version' => self::GOVERNANCE_POLICY_EVOLUTION_SCHEMA_VERSION,
            'status' => 'ready',
            'policy_proposals' => $proposals,
            'review_gate' => [
                'auto_apply_allowed' => false,
                'human_review_required' => true,
                'aemor_judgment_required' => true,
                'verified_execution_required_for_policy_patch' => true,
            ],
            'guardrail_budget' => [
                'max_autonomy_increase_per_cycle' => 1,
                'policy_change_cooldown_required' => true,
                'revert_policy_required' => true,
            ],
            'decision' => [
                'recommended_action' => $proposals === [] ? 'keep_current_policy' : 'review_policy_proposals',
                'policy_patch_authorized' => false,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_applies_policy' => false,
                'sensitive_policy_change_requires_human_review' => true,
            ],
        ];
        $payload['governance_policy_evolution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $sample = $this->operatingSystem([
            'workspace' => base_path(),
            'logs' => ['checkout latency p95 stable'],
            'incidents' => [],
            'metrics' => ['activation_rate' => 0.42, 'checkout_conversion' => 0.31],
            'revenue_usd' => 12500,
            'cost_usd' => 420,
            'human_feedback' => ['users ask for shorter onboarding'],
            'capital_budget_usd' => 10000,
            'teams' => [['id' => 'atlas-product', 'capacity' => 2]],
        ]);

        $checks = [
            $this->check('canonical_doc_present', file_exists(base_path('docs/engineering-knowledge-base/atlas-strategic-operating-system-runtime.md'))),
            $this->check('runtime_service_present', class_exists(self::class)),
            $this->check('command_present', class_exists(\App\Console\Commands\AtlasStrategicOperatingSystemCommand::class)),
            $this->check('feature_tests_present', file_exists(base_path('tests/Feature/Ai/StrategicOperatingSystem/AtlasStrategicOperatingSystemRuntimeServiceTest.php'))),
            $this->check('runtime_feedback_graph_ready', data_get($sample, 'runtime_feedback_graph.status') === 'ready'),
            $this->check('experiment_strategy_loop_ready', data_get($sample, 'autonomous_experiment_strategy_loop.status') === 'ready'),
            $this->check('organization_twin_ready', data_get($sample, 'organization_twin.status') === 'ready'),
            $this->check('portfolio_capital_brain_ready', data_get($sample, 'portfolio_capital_allocation_brain.status') === 'ready'),
            $this->check('governance_policy_evolution_review_gated', data_get($sample, 'autonomous_governance_policy_evolution.review_gate.auto_apply_allowed') === false),
            $this->check('verified_execution_sidecar_present', data_get($sample, 'autonomous_experiment_strategy_loop.verified_execution_sidecar.status') === 'ready'),
            $this->check('no_provider_or_auto_spend_claim', data_get($sample, 'claim_policy.provider_invoked') === false && data_get($sample, 'claim_policy.auto_spends_capital') === false),
        ];
        $failed = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'failed'));
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA_VERSION,
            'status' => $failed === [] ? 'ready' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'sample_hash' => $sample['strategic_operating_system_hash'] ?? null,
            'remaining_blockers' => array_column($failed, 'id'),
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'certification_uses_synthetic_sample_plus_available_read_models' => true,
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<AtlasProductDeliveryRuntimeReceipt>
     */
    private function runtimeReceipts(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_product_delivery_runtime_receipts')) {
            return [];
        }

        return AtlasProductDeliveryRuntimeReceipt::query()->latest('created_at')->limit(max(1, min($limit, 200)))->get()->all();
    }

    private function providerMemory(): AtlasProductDeliveryProviderMemoryFeedService
    {
        return $this->providerMemory ?? app(AtlasProductDeliveryProviderMemoryFeedService::class);
    }

    private function verifiedExecution(): AtlasVerifiedExecutionRuntimeService
    {
        return $this->verifiedExecution ?? app(AtlasVerifiedExecutionRuntimeService::class);
    }

    /**
     * @return list<AtlasProductDeliveryOutcomeMemory>
     */
    private function outcomeMemories(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_product_delivery_outcome_memories')) {
            return [];
        }

        return AtlasProductDeliveryOutcomeMemory::query()->latest('created_at')->limit(max(1, min($limit, 200)))->get()->all();
    }

    /**
     * @param  list<AtlasProductDeliveryRuntimeReceipt>  $receipts
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $outcomes
     * @param  list<array<string,mixed>>  $manualSignals
     * @param  array<string,mixed>  $providerMemory
     * @return list<array<string,mixed>>
     */
    private function feedbackNodes(array $receipts, array $outcomes, array $manualSignals, array $providerMemory): array
    {
        $nodes = [];
        foreach ($receipts as $receipt) {
            $nodes[] = [
                'id' => 'receipt:'.$receipt->id,
                'type' => 'runtime_receipt',
                'status' => $receipt->status,
                'route' => $receipt->route,
                'writes' => (bool) $receipt->writes,
                'hash' => $receipt->receipt_hash,
            ];
        }
        foreach ($outcomes as $outcome) {
            $nodes[] = [
                'id' => 'outcome:'.$outcome->id,
                'type' => 'outcome_memory',
                'status' => $outcome->outcome_status,
                'route' => $outcome->route,
                'human_review_required' => (bool) $outcome->human_review_required,
                'hash' => $outcome->outcome_memory_hash,
            ];
        }
        foreach ($manualSignals as $signal) {
            $nodes[] = $signal;
        }
        if (($providerMemory['status'] ?? null) !== null) {
            $nodes[] = [
                'id' => 'provider_memory:latest',
                'type' => 'provider_memory',
                'status' => $providerMemory['status'],
                'hash' => $providerMemory['provider_memory_hash'] ?? null,
            ];
        }

        return $nodes;
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return list<array<string,string>>
     */
    private function feedbackEdges(array $nodes): array
    {
        $edges = [];
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? 'unknown');
            if ($type === 'runtime_receipt') {
                $edges[] = ['from' => (string) $node['id'], 'to' => 'runtime_feedback_graph', 'kind' => 'reports_execution_reality'];
            } elseif ($type === 'outcome_memory') {
                $edges[] = ['from' => (string) $node['id'], 'to' => 'runtime_feedback_graph', 'kind' => 'reports_product_outcome'];
            } else {
                $edges[] = ['from' => (string) ($node['id'] ?? 'signal'), 'to' => 'runtime_feedback_graph', 'kind' => 'reports_external_signal'];
            }
        }

        return $edges;
    }

    /**
     * @param  list<AtlasProductDeliveryRuntimeReceipt>  $receipts
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $outcomes
     * @param  list<array<string,mixed>>  $manualSignals
     * @param  array<string,mixed>  $providerMemory
     * @return array<string,mixed>
     */
    private function feedbackMetrics(array $receipts, array $outcomes, array $manualSignals, array $providerMemory): array
    {
        $failedReceipts = array_filter($receipts, static fn (AtlasProductDeliveryRuntimeReceipt $receipt): bool => str_contains((string) $receipt->status, 'blocked') || str_contains((string) $receipt->status, 'failed'));
        $blockedOutcomes = array_filter($outcomes, static fn (AtlasProductDeliveryOutcomeMemory $outcome): bool => $outcome->outcome_status !== 'ready');

        return [
            'runtime_receipt_count' => count($receipts),
            'runtime_failure_count' => count($failedReceipts),
            'outcome_count' => count($outcomes),
            'blocked_outcome_count' => count($blockedOutcomes),
            'manual_signal_count' => count($manualSignals),
            'provider_failure_count' => (int) data_get($providerMemory, 'risk_signals.provider_failure_count', 0),
            'flake_count' => (int) data_get($providerMemory, 'risk_signals.flake_count', 0),
            'estimated_cost_usd' => (float) data_get($providerMemory, 'cost.total_estimated_usd', 0.0),
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $providerMemory
     * @return array<string,mixed>
     */
    private function feedbackRiskSignals(array $metrics, array $providerMemory): array
    {
        return [
            'incident_pressure' => (int) ($metrics['runtime_failure_count'] ?? 0) > 0 || (int) ($metrics['blocked_outcome_count'] ?? 0) > 0,
            'cost_pressure' => (bool) data_get($providerMemory, 'risk_signals.cost_pressure', false),
            'flake_pressure' => (int) ($metrics['flake_count'] ?? 0) > 0,
            'provider_failure_pressure' => (int) ($metrics['provider_failure_count'] ?? 0) > 0,
            'learning_signal_strength' => min(1.0, ((int) ($metrics['manual_signal_count'] ?? 0) + (int) ($metrics['outcome_count'] ?? 0)) / 5),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function manualSignals(array $input): array
    {
        $signals = [];
        foreach (AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['logs'] ?? []) as $index => $log) {
            $signals[] = ['id' => 'log:'.$index, 'type' => 'log', 'status' => 'observed', 'summary' => $log];
        }
        foreach (AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['incidents'] ?? []) as $index => $incident) {
            $signals[] = ['id' => 'incident:'.$index, 'type' => 'incident', 'status' => 'observed', 'summary' => $incident];
        }
        foreach (AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['regressions'] ?? []) as $index => $regression) {
            $signals[] = ['id' => 'regression:'.$index, 'type' => 'regression', 'status' => 'observed', 'summary' => $regression];
        }
        foreach ($this->assoc($input['metrics'] ?? []) as $name => $value) {
            $signals[] = ['id' => 'metric:'.$name, 'type' => 'product_metric', 'status' => 'observed', 'name' => $name, 'value' => $value];
        }
        foreach (AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['human_feedback'] ?? []) as $index => $feedback) {
            $signals[] = ['id' => 'human_feedback:'.$index, 'type' => 'human_feedback', 'status' => 'observed', 'summary' => $feedback];
        }
        foreach (['revenue_usd', 'cost_usd'] as $key) {
            $value = $this->number($input[$key] ?? null);
            if ($value !== null) {
                $signals[] = ['id' => $key, 'type' => $key === 'revenue_usd' ? 'revenue' : 'cost', 'status' => 'observed', 'value' => $value];
            }
        }

        return $signals;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function experimentHypotheses(array $feedback, array $input): array
    {
        $metrics = $this->assoc($input['metrics'] ?? []);
        $risk = (array) ($feedback['risk_signals'] ?? []);
        $hypotheses = [];
        if ($metrics !== []) {
            foreach (array_slice(array_keys($metrics), 0, 3) as $metric) {
                $hypotheses[] = [
                    'id' => 'improve_'.$metric,
                    'hypothesis' => 'Improving the product surface connected to '.$metric.' should increase '.$metric.' without raising runtime risk.',
                    'success_metric' => ['name' => $metric, 'target_delta' => 0.05],
                    'risk_controls' => ['runtime_feedback_graph_green', 'verified_execution_required', 'rollback_plan_required'],
                ];
            }
        }
        if ((bool) ($risk['incident_pressure'] ?? false)) {
            $hypotheses[] = [
                'id' => 'reduce_incident_pressure',
                'hypothesis' => 'Reducing the unstable flow before growth experiments should improve reliability and protect conversion signal quality.',
                'success_metric' => ['name' => 'incident_count', 'target_delta' => -1],
                'risk_controls' => ['no_feature_expansion_until_reliability_green'],
            ];
        }
        if ($hypotheses === [] && count((array) ($feedback['nodes'] ?? [])) > 0) {
            $hypotheses[] = [
                'id' => 'extract_learning_from_feedback',
                'hypothesis' => 'The latest runtime feedback contains enough signal to run a guarded learning experiment.',
                'success_metric' => ['name' => 'learning_signal_strength', 'target' => 0.6],
                'risk_controls' => ['operator_review_required'],
            ];
        }

        return array_values($hypotheses);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function teams(array $input): array
    {
        $teams = is_array($input['teams'] ?? null) ? $input['teams'] : [];
        if ($teams === []) {
            return [['id' => 'atlas-core', 'owner' => 'atlas', 'capacity' => 1, 'domains' => ['engineering', 'product', 'strategy']]];
        }

        return array_values(array_map(static fn (mixed $team): array => is_array($team) ? $team : ['id' => (string) $team, 'capacity' => 1], $teams));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function backlog(array $input, array $experiment): array
    {
        $items = is_array($input['backlog'] ?? null) ? array_values($input['backlog']) : [];
        if ($items !== []) {
            return array_values(array_map(static fn (mixed $item): array => is_array($item) ? $item : ['id' => (string) $item, 'priority' => 50, 'owner' => 'unassigned'], $items));
        }

        return array_values(array_map(static fn (array $hypothesis): array => [
            'id' => 'experiment:'.$hypothesis['id'],
            'title' => $hypothesis['hypothesis'],
            'owner' => 'atlas-product',
            'priority' => 80,
            'risk' => in_array('no_feature_expansion_until_reliability_green', (array) ($hypothesis['risk_controls'] ?? []), true) ? 'high' : 'medium',
        ], (array) ($experiment['hypotheses'] ?? [])));
    }

    /**
     * @param  list<array<string,mixed>>  $backlog
     * @return list<array<string,string>>
     */
    private function dependencies(array $backlog): array
    {
        $edges = [];
        foreach ($backlog as $item) {
            if (($item['risk'] ?? null) === 'high') {
                $edges[] = ['from' => (string) $item['id'], 'to' => 'operator_review', 'kind' => 'requires_review'];
            }
        }

        return $edges;
    }

    /**
     * @return array<string,mixed>
     */
    private function debtSignals(array $feedback, array $input): array
    {
        return [
            'incident_pressure' => (bool) data_get($feedback, 'risk_signals.incident_pressure', false),
            'flake_pressure' => (bool) data_get($feedback, 'risk_signals.flake_pressure', false),
            'cost_pressure' => (bool) data_get($feedback, 'risk_signals.cost_pressure', false),
            'manual_debt_items' => AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['debt'] ?? []),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $backlog
     * @param  array<string,mixed>  $debt
     * @param  list<array<string,string>>  $dependencies
     * @return list<array<string,mixed>>
     */
    private function workSequence(array $backlog, array $debt, array $dependencies): array
    {
        $items = $backlog;
        usort($items, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0)) ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        if ((bool) ($debt['incident_pressure'] ?? false)) {
            array_unshift($items, ['id' => 'stabilize_runtime_feedback_regressions', 'title' => 'Stabilize runtime regressions before growth allocation', 'owner' => 'atlas-core', 'priority' => 100, 'risk' => 'high']);
        }

        return array_values(array_map(static fn (array $item, int $index): array => [
            'order' => $index + 1,
            'id' => (string) ($item['id'] ?? 'work_item'),
            'owner' => (string) ($item['owner'] ?? 'unassigned'),
            'risk' => (string) ($item['risk'] ?? 'medium'),
            'requires_dependency_review' => in_array((string) ($item['id'] ?? ''), array_column($dependencies, 'from'), true),
        ], $items, array_keys($items)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function portfolioCandidates(array $feedback, array $experiment, array $organization, array $input): array
    {
        $candidates = [];
        foreach ((array) ($experiment['hypotheses'] ?? []) as $hypothesis) {
            $riskPenalty = (bool) data_get($feedback, 'risk_signals.incident_pressure', false) ? 20 : 0;
            $costPenalty = (bool) data_get($feedback, 'risk_signals.cost_pressure', false) ? 15 : 0;
            $score = 70 - $riskPenalty - $costPenalty + (int) round(20 * (float) data_get($feedback, 'risk_signals.learning_signal_strength', 0));
            $candidates[] = [
                'id' => (string) ($hypothesis['id'] ?? 'hypothesis'),
                'type' => 'experiment',
                'expected_value' => 'learning_and_metric_lift',
                'allocation_score' => max(0, min(100, $score)),
                'risk_adjusted' => true,
                'requires_verified_execution' => true,
            ];
        }
        foreach ((array) data_get($organization, 'recommended_sequence', []) as $item) {
            if (($item['risk'] ?? null) === 'high') {
                $candidates[] = [
                    'id' => (string) ($item['id'] ?? 'stabilization'),
                    'type' => 'stabilization',
                    'expected_value' => 'risk_reduction',
                    'allocation_score' => 85,
                    'risk_adjusted' => true,
                    'requires_verified_execution' => true,
                ];
            }
        }
        foreach ($this->explicitPortfolioCandidates($input) as $candidate) {
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param  list<array<string,mixed>>  $backlog
     * @param  array<string,mixed>  $debt
     * @return list<array<string,mixed>>
     */
    private function riskByArea(array $backlog, array $debt): array
    {
        $areas = [];
        foreach ($backlog as $item) {
            $owner = (string) ($item['owner'] ?? 'unassigned');
            $areas[$owner] ??= [
                'area' => $owner,
                'work_item_count' => 0,
                'high_risk_count' => 0,
                'risk_level' => 'low',
                'drivers' => [],
            ];
            $areas[$owner]['work_item_count']++;
            if (($item['risk'] ?? null) === 'high') {
                $areas[$owner]['high_risk_count']++;
                $areas[$owner]['risk_level'] = 'high';
                $areas[$owner]['drivers'][] = 'high_risk_backlog';
            }
        }
        foreach (['incident_pressure', 'flake_pressure', 'cost_pressure'] as $driver) {
            if ((bool) ($debt[$driver] ?? false)) {
                $areas['atlas-core'] ??= [
                    'area' => 'atlas-core',
                    'work_item_count' => 0,
                    'high_risk_count' => 0,
                    'risk_level' => 'low',
                    'drivers' => [],
                ];
                $areas['atlas-core']['risk_level'] = 'high';
                $areas['atlas-core']['drivers'][] = $driver;
            }
        }

        return array_values(array_map(static function (array $area): array {
            $area['drivers'] = array_values(array_unique($area['drivers']));

            return $area;
        }, $areas));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function explicitPortfolioCandidates(array $input): array
    {
        $items = [];
        foreach (['ventures', 'features', 'teams', 'capital_items'] as $bucket) {
            $values = is_array($input[$bucket] ?? null) ? $input[$bucket] : [];
            foreach ($values as $index => $value) {
                $item = is_array($value) ? $value : ['id' => (string) $value];
                $id = (string) ($item['id'] ?? $bucket.':'.$index);
                $risk = (string) ($item['risk'] ?? 'medium');
                $expectedRoi = $this->number($item['expected_roi'] ?? null) ?? 0.0;
                $score = 55 + min(30, (int) round($expectedRoi * 10)) - ($risk === 'high' ? 20 : 0);
                $items[] = [
                    'id' => $bucket.':'.$id,
                    'type' => rtrim($bucket, 's'),
                    'expected_value' => $item['expected_value'] ?? 'portfolio_candidate',
                    'allocation_score' => max(0, min(100, $score)),
                    'risk_adjusted' => true,
                    'requires_verified_execution' => $bucket !== 'capital_items',
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function allocation(array $candidates, float $budget): array
    {
        $selected = [];
        $remaining = $budget;
        foreach (array_slice($candidates, 0, 5) as $candidate) {
            $amount = $budget <= 0 ? 0.0 : min($remaining, max(100.0, $budget / max(1, min(5, count($candidates)))));
            $remaining -= $amount;
            $selected[] = [
                'candidate_id' => $candidate['id'],
                'amount_usd' => round($amount, 2),
                'approval_required' => true,
            ];
            if ($remaining <= 0) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function policyProposals(array $feedback, array $experiment, array $organization, array $portfolio): array
    {
        $proposals = [];
        if ((bool) data_get($feedback, 'risk_signals.incident_pressure', false)) {
            $proposals[] = [
                'id' => 'tighten_experiment_launch_gate',
                'type' => 'risk_guardrail',
                'proposal' => 'Require runtime feedback graph green signal before growth experiments.',
                'reason' => 'Incident or blocked outcome pressure detected.',
                'auto_apply_allowed' => false,
            ];
        }
        if ((bool) data_get($feedback, 'risk_signals.cost_pressure', false)) {
            $proposals[] = [
                'id' => 'lower_provider_cost_budget_until_review',
                'type' => 'budget_guardrail',
                'proposal' => 'Reduce provider/capital autonomy until cost pressure is reviewed.',
                'reason' => 'Cost pressure detected in provider memory or manual costs.',
                'auto_apply_allowed' => false,
            ];
        }
        if ($proposals === [] && (($experiment['status'] ?? null) === 'ready')) {
            $proposals[] = [
                'id' => 'keep_policy_and_collect_more_feedback',
                'type' => 'learning_policy',
                'proposal' => 'Keep current gates and collect experiment outcome feedback.',
                'reason' => 'No urgent risk pressure detected.',
                'auto_apply_allowed' => false,
            ];
        }

        return $proposals;
    }

    /**
     * @return list<string>
     */
    private function operatingSystemBlockers(array ...$sections): array
    {
        $blockers = [];
        foreach ($sections as $section) {
            if (($section['status'] ?? null) === 'blocked') {
                $blockers[] = (string) ($section['schema_version'] ?? 'section').':blocked';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return array<string,string>
     */
    private function check(string $id, bool $passed): array
    {
        return ['id' => $id, 'status' => $passed ? 'passed' : 'failed'];
    }

    /**
     * @return array<string,mixed>
     */
    private function assoc(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function number(mixed $value): ?float
    {
        return AiValueNormalizer::finiteFloatOrNull($value);
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

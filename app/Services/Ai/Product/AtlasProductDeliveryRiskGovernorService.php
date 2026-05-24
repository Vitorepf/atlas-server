<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasProductDeliveryRiskGovernorService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.risk_governor.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $simulation
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $delivery, array $proof = [], array $simulation = [], array $options = []): array
    {
        $route = (string) ($delivery['route'] ?? 'unknown');
        $lenses = $this->list(data_get($delivery, 'product_truth.execution_lenses.required', []));
        $runtimeSignals = $this->runtimeSignals($options);
        $riskFactors = $this->riskFactors($delivery, $proof, $simulation, $options, $lenses, $runtimeSignals);
        $riskScore = $this->riskScore($route, $riskFactors, $proof, $simulation);
        $riskBand = $this->riskBand($riskScore);
        $approvals = $this->approvals($route, $riskBand, $riskFactors, $options);
        $blockers = $this->blockers($delivery, $proof, $simulation, $approvals, $options, $runtimeSignals);
        $approved = $this->approved($options);
        $blocked = $blockers !== [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blocked ? 'blocked' : 'allowed',
            'mode' => 'provider_free_read_only',
            'route' => $route,
            'risk_score' => $riskScore,
            'risk_band' => $riskBand,
            'risk_factors' => $riskFactors,
            'runtime_signals' => $runtimeSignals,
            'governor_decision' => [
                'max_autonomy_level' => $this->maxAutonomyLevel($blocked, $approved, $riskBand, $riskFactors),
                'reason' => $this->decisionReason($blocked, $approved, $riskBand, $riskFactors),
                'may_increase_autonomy' => false,
                'may_reduce_autonomy' => true,
            ],
            'autonomy_budget' => [
                'provider_plan_allowed' => ! $blocked,
                'provider_patch_apply_allowed' => ! $blocked && $approved,
                'mutative_repair_allowed' => ! $blocked && $approved,
                'completion_allowed' => ! $blocked && (($proof['status'] ?? null) === 'ready'),
                'requires_human_approval' => $approvals !== [],
            ],
            'required_approvals' => $approvals,
            'required_gates' => $this->requiredGates($riskBand, $riskFactors),
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'risk_governor_is_not_execution' => true,
                'human_approval_required_for_mutation' => true,
            ],
        ];
        $payload['risk_governor_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $lenses
     * @param  array<string,mixed>  $runtimeSignals
     * @return list<string>
     */
    private function riskFactors(array $delivery, array $proof, array $simulation, array $options, array $lenses, array $runtimeSignals): array
    {
        $factors = [];
        if (($delivery['route'] ?? null) === 'atlas_forge') {
            $factors[] = 'forge_long_running_work';
        }
        if (data_get($delivery, 'proof_requirements.apfpr_required') === true) {
            $factors[] = 'apfpr_required';
        }
        foreach (['security_driven', 'performance_driven', 'add', 'cdd', 'api_first'] as $lens) {
            if (in_array($lens, $lenses, true)) {
                $factors[] = $lens;
            }
        }
        if (($proof['status'] ?? null) !== 'ready') {
            $factors[] = 'proof_not_ready';
        }
        if ($this->list($proof['critical_blockers'] ?? []) !== []) {
            $factors[] = 'critical_proof_blockers';
        }
        if ($this->list(data_get($simulation, 'risk_forecast.blockers', [])) !== []) {
            $factors[] = 'simulation_blocked';
        }
        if ((bool) ($options['provider_patch'] ?? false)) {
            $factors[] = 'provider_patch_candidate';
        }
        if (($runtimeSignals['replay_status'] ?? null) === 'blocked') {
            $factors[] = 'evidence_replay_blocked';
        }
        if ((int) ($runtimeSignals['scenario_failures'] ?? 0) > 0) {
            $factors[] = 'scenario_replay_failure';
        }
        if ((int) ($runtimeSignals['unsafe_write_receipt_count'] ?? 0) > 0) {
            $factors[] = 'unsafe_write_receipt_history';
        }
        if ((int) ($runtimeSignals['approval_required_receipt_count'] ?? 0) > 0) {
            $factors[] = 'approval_friction_history';
        }
        if ((int) ($runtimeSignals['doctrine_policy_proposal_count'] ?? 0) > 0) {
            $factors[] = 'doctrine_fitness_policy_pressure';
        }
        if ((float) ($runtimeSignals['lowest_fitness_score'] ?? 1.0) < 0.55) {
            $factors[] = 'low_doctrine_fitness_score';
        }
        if ((int) ($runtimeSignals['provider_failure_count'] ?? 0) > 0) {
            $factors[] = 'provider_failure_pressure';
        }
        if ((int) ($runtimeSignals['flake_count'] ?? 0) > 0 || (int) ($options['flake_count'] ?? 0) > 0) {
            $factors[] = 'test_flake_pressure';
        }
        if ((bool) ($runtimeSignals['cost_pressure'] ?? false) || (bool) ($options['cost_pressure'] ?? false)) {
            $factors[] = 'cost_pressure';
        }

        return array_values(array_unique($factors));
    }

    /**
     * @param  list<string>  $riskFactors
     */
    private function riskScore(string $route, array $riskFactors, array $proof, array $simulation): int
    {
        $score = $route === 'atlas_forge' ? 30 : 10;
        $weights = [
            'forge_long_running_work' => 12,
            'apfpr_required' => 16,
            'security_driven' => 14,
            'performance_driven' => 10,
            'add' => 12,
            'cdd' => 8,
            'api_first' => 8,
            'proof_not_ready' => 18,
            'critical_proof_blockers' => 18,
            'simulation_blocked' => 16,
            'provider_patch_candidate' => 12,
            'evidence_replay_blocked' => 30,
            'scenario_replay_failure' => 24,
            'unsafe_write_receipt_history' => 28,
            'approval_friction_history' => 10,
            'doctrine_fitness_policy_pressure' => 14,
            'low_doctrine_fitness_score' => 16,
            'provider_failure_pressure' => 12,
            'test_flake_pressure' => 10,
            'cost_pressure' => 6,
        ];
        foreach ($riskFactors as $factor) {
            $score += $weights[$factor] ?? 4;
        }
        if (($proof['status'] ?? null) === 'ready') {
            $score -= 18;
        }
        if (($simulation['status'] ?? null) === 'simulated') {
            $score -= 8;
        }

        return max(0, min(100, $score));
    }

    private function riskBand(int $riskScore): string
    {
        return match (true) {
            $riskScore >= 75 => 'critical',
            $riskScore >= 50 => 'high',
            $riskScore >= 25 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  list<string>  $riskFactors
     * @return list<string>
     */
    private function approvals(string $route, string $riskBand, array $riskFactors, array $options): array
    {
        $approvals = [];
        if (in_array($riskBand, ['critical', 'high'], true)) {
            $approvals[] = 'operator_delivery_risk_acceptance';
        }
        if ($route === 'atlas_forge') {
            $approvals[] = 'forge_scope_approval';
        }
        if (in_array('provider_patch_candidate', $riskFactors, true)) {
            $approvals[] = 'provider_patch_apply_approval';
        }
        if ($this->approved($options)) {
            return [];
        }

        return array_values(array_unique($approvals));
    }

    /**
     * @param  list<string>  $approvals
     * @return list<array<string,mixed>>
     */
    private function blockers(array $delivery, array $proof, array $simulation, array $approvals, array $options, array $runtimeSignals): array
    {
        $blockers = [];
        if (($delivery['status'] ?? null) !== 'ready_for_delivery') {
            $blockers[] = ['id' => 'delivery_not_ready', 'severity' => 'critical'];
        }
        if (($simulation['status'] ?? 'simulated') === 'blocked') {
            $blockers[] = ['id' => 'product_twin_blocked', 'severity' => 'critical'];
        }
        if (($options['phase'] ?? null) === 'completion' && (($proof['status'] ?? null) !== 'ready')) {
            $blockers[] = ['id' => 'completion_requires_ready_proof', 'severity' => 'critical'];
        }
        if (($runtimeSignals['replay_status'] ?? null) === 'blocked') {
            $blockers[] = ['id' => 'evidence_replay_blocked', 'severity' => 'critical'];
        }
        if ((int) ($runtimeSignals['unsafe_write_receipt_count'] ?? 0) > 0) {
            $blockers[] = ['id' => 'unsafe_write_receipts_detected', 'severity' => 'critical'];
        }
        if ($approvals !== []) {
            $blockers[] = [
                'id' => 'human_approval_required',
                'severity' => 'critical',
                'required_approvals' => $approvals,
            ];
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $riskFactors
     * @return list<string>
     */
    private function requiredGates(string $riskBand, array $riskFactors): array
    {
        $gates = ['product_truth_contract', 'product_twin_simulation', 'scope_guard', 'outcome_memory'];
        if (in_array($riskBand, ['critical', 'high'], true)) {
            $gates[] = 'apfpr_ready';
            $gates[] = 'operator_risk_receipt';
        }
        if (in_array('security_driven', $riskFactors, true)) {
            $gates[] = 'security_regression_evidence';
        }
        if (in_array('provider_patch_candidate', $riskFactors, true)) {
            $gates[] = 'patch_proposal_gate';
            $gates[] = 'rollback_snapshot';
        }
        if (array_intersect($riskFactors, ['evidence_replay_blocked', 'scenario_replay_failure', 'unsafe_write_receipt_history']) !== []) {
            $gates[] = 'evidence_replay_green';
            $gates[] = 'runtime_receipt_audit';
        }
        if (array_intersect($riskFactors, ['doctrine_fitness_policy_pressure', 'low_doctrine_fitness_score']) !== []) {
            $gates[] = 'doctrine_fitness_review';
        }
        if (in_array('provider_failure_pressure', $riskFactors, true)) {
            $gates[] = 'provider_memory_review';
        }
        if (in_array('test_flake_pressure', $riskFactors, true)) {
            $gates[] = 'flake_triage';
        }

        return array_values(array_unique($gates));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runtimeSignals(array $options): array
    {
        $replay = is_array($options['replay_report'] ?? null) ? $options['replay_report'] : [];
        $fitness = is_array($options['doctrine_fitness'] ?? null) ? $options['doctrine_fitness'] : [];
        $providerMemory = is_array($options['provider_memory_feed'] ?? null) ? $options['provider_memory_feed'] : [];
        $receiptHealth = $this->receiptHealth((int) ($options['receipt_limit'] ?? 25));
        $scenarioCount = (int) ($replay['scenario_count'] ?? 0);
        $passedScenarioCount = (int) ($replay['passed_scenario_count'] ?? 0);
        $fitnessScores = $this->fitnessScores($fitness);

        return [
            'replay_status' => $replay['status'] ?? 'not_provided',
            'scenario_count' => $scenarioCount,
            'scenario_failures' => max(0, $scenarioCount - $passedScenarioCount),
            'unsafe_write_receipt_count' => max(
                (int) data_get($replay, 'receipt_replay.unsafe_write_receipt_count', 0),
                (int) ($receiptHealth['unsafe_write_receipt_count'] ?? 0),
            ),
            'approval_required_receipt_count' => (int) ($receiptHealth['approval_required_receipt_count'] ?? 0),
            'receipt_sample_size' => (int) ($receiptHealth['receipt_sample_size'] ?? 0),
            'doctrine_fitness_status' => $fitness['status'] ?? 'not_provided',
            'doctrine_sample_size' => (int) ($fitness['sample_size'] ?? 0),
            'doctrine_policy_proposal_count' => is_array($fitness['policy_proposals'] ?? null)
                ? count($fitness['policy_proposals'])
                : 0,
            'lowest_fitness_score' => $fitnessScores === [] ? null : min($fitnessScores),
            'provider_memory_status' => $providerMemory['status'] ?? 'not_provided',
            'provider_failure_count' => (int) data_get($providerMemory, 'risk_signals.provider_failure_count', 0),
            'cost_pressure' => (bool) data_get($providerMemory, 'risk_signals.cost_pressure', false),
            'flake_count' => (int) data_get($providerMemory, 'risk_signals.flake_count', 0),
            'outcome_pressure_score' => (float) data_get($providerMemory, 'risk_signals.outcome_pressure_score', 0.0),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function receiptHealth(int $limit): array
    {
        try {
            if (! Schema::hasTable('atlas_product_delivery_runtime_receipts')) {
                return [
                    'receipt_sample_size' => 0,
                    'unsafe_write_receipt_count' => 0,
                    'approval_required_receipt_count' => 0,
                ];
            }
        } catch (Throwable) {
            return [
                'receipt_sample_size' => 0,
                'unsafe_write_receipt_count' => 0,
                'approval_required_receipt_count' => 0,
            ];
        }

        $records = AtlasProductDeliveryRuntimeReceipt::query()
            ->latest('created_at')
            ->limit(max(1, min($limit, 100)))
            ->get();
        $unsafe = 0;
        $approvalRequired = 0;
        foreach ($records as $record) {
            $payload = is_array($record->payload) ? $record->payload : [];
            $approvalRequired = data_get($payload, 'operator_decision_contract.required');
            $approvalPresent = (bool) data_get($payload, 'patch_proposal_gate.operator_decision.approval')
                || (bool) data_get($payload, 'operator_decision.approval')
                || $approvalRequired === false;
            if ((bool) $record->writes && ! $approvalPresent) {
                $unsafe++;
            }
            if (in_array((string) $record->status, ['needs_human_approval', 'blocked'], true)
                || data_get($payload, 'approval_required') === true) {
                $approvalRequired++;
            }
        }

        return [
            'receipt_sample_size' => $records->count(),
            'unsafe_write_receipt_count' => $unsafe,
            'approval_required_receipt_count' => $approvalRequired,
        ];
    }

    /**
     * @param  array<string,mixed>  $fitness
     * @return list<float>
     */
    private function fitnessScores(array $fitness): array
    {
        $scores = [];
        foreach (['route_fitness', 'lens_fitness', 'evidence_fitness', 'repair_fitness'] as $key) {
            $items = is_array($fitness[$key] ?? null) ? $fitness[$key] : [];
            foreach ($items as $item) {
                if (is_array($item) && is_numeric($item['fitness_score'] ?? null)) {
                    $scores[] = (float) $item['fitness_score'];
                }
            }
        }

        return $scores;
    }

    /**
     * @param  list<string>  $riskFactors
     */
    private function maxAutonomyLevel(bool $blocked, bool $approved, string $riskBand, array $riskFactors): string
    {
        if ($blocked) {
            return 'plan_only';
        }
        if (array_intersect($riskFactors, ['evidence_replay_blocked', 'unsafe_write_receipt_history', 'scenario_replay_failure']) !== []) {
            return 'plan_only';
        }
        if (! $approved && in_array($riskBand, ['critical', 'high'], true)) {
            return 'patch_request_only';
        }
        if ($approved && in_array('provider_patch_candidate', $riskFactors, true)) {
            return 'approved_mutative_repair';
        }

        return 'assisted_execution';
    }

    /**
     * @param  list<string>  $riskFactors
     */
    private function decisionReason(bool $blocked, bool $approved, string $riskBand, array $riskFactors): string
    {
        if ($blocked) {
            return 'risk_blockers_present';
        }
        if ($approved) {
            return 'operator_approval_present_with_required_gates';
        }
        if (in_array($riskBand, ['critical', 'high'], true)) {
            return 'high_risk_requires_operator_approval';
        }
        if ($riskFactors !== []) {
            return 'autonomy_reduced_by_runtime_signals';
        }

        return 'low_risk_assisted_execution';
    }

    private function approved(array $options): bool
    {
        return (bool) ($options['operator_approved'] ?? false);
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }
}

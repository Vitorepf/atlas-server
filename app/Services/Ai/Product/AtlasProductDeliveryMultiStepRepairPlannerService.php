<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductDeliveryMultiStepRepairPlannerService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.multi_step_repair_plan.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $repairBridge
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $delivery, array $proof, array $repairBridge, array $options = []): array
    {
        $route = (string) ($delivery['route'] ?? data_get($delivery, 'product_truth.execution_decomposition.route', 'atlas_dev'));
        $riskBand = $this->riskBand($delivery);
        $proofReady = ($proof['status'] ?? null) === 'ready';
        $steps = $proofReady ? [] : $this->steps($delivery, $proof, $repairBridge, $route, $riskBand);
        $budgets = $this->budgets($route, $riskBand, count($steps), $options);
        $blockers = $this->blockers($delivery, $proof, $repairBridge, $steps);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $proofReady ? 'not_required' : ($blockers === [] ? 'planned' : 'blocked'),
            'mode' => 'provider_free_read_only',
            'route' => $route,
            'risk_band' => $riskBand,
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'proof_hash' => (string) ($proof['proof_hash'] ?? ''),
            'repair_bridge_hash' => (string) ($repairBridge['repair_bridge_hash'] ?? ''),
            'budgets' => $budgets,
            'rollback_policy' => [
                'required' => true,
                'required_before_any_write' => true,
                'snapshot_per_step' => true,
                'restore_on_failed_proof' => true,
            ],
            'steps' => $steps,
            'stop_conditions' => [
                'proof_ready',
                'budget_exhausted',
                'proposal_gate_blocked',
                'rollback_unavailable',
                'operator_denied_approval',
            ],
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'planner_is_not_executor' => true,
                'rollback_required_for_mutation' => true,
            ],
        ];
        $payload['repair_plan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $repairBridge
     * @return list<array<string,mixed>>
     */
    private function steps(array $delivery, array $proof, array $repairBridge, string $route, string $riskBand): array
    {
        $tests = $this->list(data_get($delivery, 'delivery_plan.tests', []));
        $requiredEvidence = $this->list(data_get($repairBridge, 'repair.required_evidence', []));

        return [
            [
                'id' => 'context_refresh',
                'kind' => 'read_only',
                'purpose' => 'Refresh Product Truth, allowed files, tests, contracts, and APFPR blockers.',
                'required_input' => ['delivery_contract', 'proof_challenge', 'repair_bridge'],
                'expected_output' => 'fresh_context_pack',
                'rollback_required' => false,
                'stop_if' => ['product_truth_not_ready'],
            ],
            [
                'id' => 'patch_request_projection',
                'kind' => 'provider_or_subagent_safe_projection',
                'purpose' => 'Create the patch request contract and required patch manifest schema.',
                'required_input' => ['fresh_context_pack', 'allowed_files', 'non_goals', 'focused_test_evidence', 'security_evidence_if_required'],
                'expected_output' => 'atlas.product_delivery.patch_request_contract.v1',
                'rollback_required' => false,
                'stop_if' => ['missing_allowed_files_for_mutation'],
            ],
            [
                'id' => 'patch_proposal_gate',
                'kind' => 'gate',
                'purpose' => 'Validate patch manifest and require operator approval when source/risk requires it.',
                'required_input' => ['patch_manifest', 'source_kind', 'approval_if_required'],
                'expected_output' => 'atlas.product_delivery.patch_proposal_gate.v1',
                'rollback_required' => false,
                'stop_if' => ['proposal_gate_blocked', 'operator_denied_approval'],
            ],
            [
                'id' => 'dry_run_repair',
                'kind' => 'dry_run',
                'purpose' => 'Build rollback snapshot and preflight all file operations before writes.',
                'required_input' => ['sanitized_patch_manifest'],
                'expected_output' => 'dry_run_ready',
                'rollback_required' => true,
                'stop_if' => ['rollback_unavailable', 'expected_hash_mismatch'],
            ],
            [
                'id' => 'apply_and_verify',
                'kind' => $riskBand === 'high' ? 'operator_approved_mutation' : 'controlled_mutation',
                'purpose' => 'Apply explicit patch only after gate approval, then rerun proof.',
                'required_input' => ['operator_approval_if_required', 'rollback_snapshot', 'proof_evidence'],
                'expected_output' => 'applied_and_verified',
                'rollback_required' => true,
                'stop_if' => ['proof_not_ready_after_repair', 'budget_exhausted'],
            ],
            [
                'id' => 'persist_outcome',
                'kind' => 'memory_and_receipt',
                'purpose' => 'Persist runtime receipts and outcome memory through AEMOR Judgment Guard.',
                'required_input' => array_values(array_unique(array_merge($tests, $requiredEvidence, ['repair_execution_receipt']))),
                'expected_output' => 'outcome_memory_recorded',
                'rollback_required' => false,
                'stop_if' => ['aemor_judgment_rejected_learning'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function budgets(string $route, string $riskBand, int $stepCount, array $options): array
    {
        $maxSteps = max($stepCount, 1);
        $cpuSeconds = (int) ($options['cpu_seconds_budget'] ?? ($route === 'atlas_forge' ? 180 : 60));
        $attempts = (int) ($options['max_attempts'] ?? ($riskBand === 'high' ? 2 : 1));

        return [
            'max_steps' => $maxSteps,
            'max_attempts' => max(1, min($attempts, 3)),
            'cpu_seconds_budget' => max(15, min($cpuSeconds, 300)),
            'token_budget_policy' => 'reuse_context_and_send_failure_capsules_only',
            'operator_resource_priority' => 'operator_first',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     * @return list<array<string,mixed>>
     */
    private function blockers(array $delivery, array $proof, array $repairBridge, array $steps): array
    {
        $blockers = [];
        if (($delivery['schema_version'] ?? null) !== AtlasAutonomousProductDeliveryRuntimeService::SCHEMA_VERSION) {
            $blockers[] = ['id' => 'invalid_delivery_contract'];
        }
        if (($proof['schema_version'] ?? null) !== AtlasProductFalsificationProofRuntimeService::SCHEMA_VERSION) {
            $blockers[] = ['id' => 'invalid_proof_challenge'];
        }
        if (($proof['status'] ?? null) !== 'ready' && ($repairBridge['status'] ?? null) !== 'repair_required') {
            $blockers[] = ['id' => 'missing_repair_bridge'];
        }
        if (($proof['status'] ?? null) !== 'ready' && $steps === []) {
            $blockers[] = ['id' => 'missing_repair_steps'];
        }

        return $blockers;
    }

    private function riskBand(array $delivery): string
    {
        $lenses = $this->list(data_get($delivery, 'delivery_plan.required_lenses', []));

        return array_intersect($lenses, ['security_driven', 'add', 'performance_driven']) !== []
            ? 'high'
            : 'standard';
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

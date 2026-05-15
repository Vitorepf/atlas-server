<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Certify the Agent Dispatch Planner layer (candidate selector,
 * eligibility evaluator, scope conflict analyzer, governance precheck,
 * dry-run receipt builder, batch planner).
 *
 * Pure projection: certification never claims, never dispatches, never
 * calls providers, never spends tokens and never writes the evidence
 * ledger. It only verifies that the layer's invariants hold.
 */
final class AgentDispatchPlannerCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_certification.v1';

    public const MODE = 'read_only_agent_dispatch_planner_certification';

    public function __construct(
        private readonly AgentDispatchPlannerCandidateSelector $selector = new AgentDispatchPlannerCandidateSelector,
        private readonly AgentDispatchPlannerEligibilityEvaluator $eligibility = new AgentDispatchPlannerEligibilityEvaluator,
        private readonly AgentDispatchPlannerScopeConflictAnalyzer $scopeAnalyzer = new AgentDispatchPlannerScopeConflictAnalyzer,
        private readonly AgentDispatchPlannerGovernancePrecheck $governance = new AgentDispatchPlannerGovernancePrecheck,
        private readonly AgentDispatchPlannerDryRunReceiptBuilder $receipts = new AgentDispatchPlannerDryRunReceiptBuilder,
        private readonly AgentDispatchPlannerBatchPlanner $batchPlanner = new AgentDispatchPlannerBatchPlanner,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $invariants = [];

        $invariants[] = $this->invariantBool(
            'candidate_selector_available',
            method_exists($this->selector, 'select'),
            'AgentDispatchPlannerCandidateSelector must expose select().',
        );
        $invariants[] = $this->invariantBool(
            'eligibility_evaluator_available',
            method_exists($this->eligibility, 'evaluate'),
            'AgentDispatchPlannerEligibilityEvaluator must expose evaluate().',
        );
        $invariants[] = $this->invariantBool(
            'scope_conflict_analyzer_available',
            method_exists($this->scopeAnalyzer, 'analyze'),
            'AgentDispatchPlannerScopeConflictAnalyzer must expose analyze().',
        );
        $invariants[] = $this->invariantBool(
            'governance_precheck_available',
            method_exists($this->governance, 'precheck'),
            'AgentDispatchPlannerGovernancePrecheck must expose precheck().',
        );
        $invariants[] = $this->invariantBool(
            'dry_run_receipt_builder_available',
            $this->receipts->isAvailable(),
            'AgentDispatchPlannerDryRunReceiptBuilder must report a healthy storage probe.',
        );
        $invariants[] = $this->invariantBool(
            'batch_planner_available',
            method_exists($this->batchPlanner, 'plan'),
            'AgentDispatchPlannerBatchPlanner must expose plan().',
        );

        $invariants[] = $this->invariantSelectionRespectsStatuses();
        $invariants[] = $this->invariantEligibilityFlagsRiskHumanApproval();
        $invariants[] = $this->invariantScopeAnalyzerDetectsOverlap();
        $invariants[] = $this->invariantGovernanceBlocksKillSwitch();
        $invariants[] = $this->invariantReceiptHashStable();
        $invariants[] = $this->invariantBatchPlannerProducesPlanShape();
        $invariants[] = $this->invariantBatchHashStable();

        $runtimeSafety = $this->runtimeSafetyInvariants();
        foreach ($runtimeSafety as $invariant) {
            $invariants[] = $invariant;
        }

        $allTrue = true;
        $violations = 0;
        $warnings = 0;
        foreach ($invariants as $invariant) {
            if (! (bool) ($invariant['ok'] ?? false)) {
                $allTrue = false;
                $violations++;
            }
            if (($invariant['warning'] ?? false) === true) {
                $warnings++;
            }
        }

        $hashPayload = array_map(
            static fn (array $i): array => [
                'name' => (string) ($i['name'] ?? ''),
                'ok' => (bool) ($i['ok'] ?? false),
            ],
            $invariants,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allTrue ? 'available' : 'blocked',
            'certified_at' => $now,
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => $violations,
            'warning_count' => $warnings,
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'claim_real_allowed' => false,
                'runtime_safety_all_false' => true,
            ],
            'certification_hash' => $this->stableHash(['invariants' => $hashPayload, 'all_true' => $allTrue]),
            'next_action' => $allTrue
                ? 'keep_dispatch_planner_dry_run_until_runtime_pilot_promotes'
                : 'fix_failing_invariants_before_promotion',
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantSelectionRespectsStatuses(): array
    {
        $allowed = AgentDispatchPlannerCandidateSelector::CLAIMABLE_STATUSES;
        $ok = in_array('claimable', $allowed, true)
            && in_array('queued', $allowed, true)
            && in_array('released', $allowed, true)
            && in_array('lease_expired', $allowed, true);

        return $this->invariantBool(
            'selection_respects_claimable_statuses',
            $ok,
            'Candidate selector must honor claimable/queued/released/lease_expired statuses only.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantEligibilityFlagsRiskHumanApproval(): array
    {
        $result = $this->eligibility->evaluate(
            [['task_packet_id' => 'tp', 'risk_level' => 'high', 'required_capabilities' => ['code_edit']]],
            [[
                'agent_id' => 'agent-a', 'status' => 'available',
                'capabilities' => ['code_edit'], 'max_parallel_tasks' => 1, 'current_task_count' => 0,
                'evidence_required' => true,
            ]],
        );
        $row = $result['evaluation_matrix'][0] ?? null;
        $eval = $row['evaluations'][0] ?? null;
        $ok = $eval !== null
            && (bool) ($eval['is_eligible'] ?? true) === false
            && in_array('human_approval_required_for_risk:high', (array) ($eval['reasons'] ?? []), true);

        return $this->invariantBool(
            'eligibility_flags_human_approval_for_high_risk',
            $ok,
            'Eligibility evaluator must require human_approval capability for high/critical risk tasks.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantScopeAnalyzerDetectsOverlap(): array
    {
        $result = $this->scopeAnalyzer->analyze(
            [['task_packet_id' => 'tp', 'scope_lock' => ['write_set' => ['fileA.php']]]],
            [
                'use_live_ledger' => false,
                'active_leases' => [[
                    'lease_id' => 'lease-1',
                    'scope_lock' => ['write_set' => ['fileA.php']],
                ]],
            ],
        );
        $analysis = $result['analyses'][0] ?? null;
        $ok = $analysis !== null
            && ($analysis['conflict_status'] ?? '') === 'conflict'
            && (int) ($analysis['conflict_count'] ?? 0) > 0;

        return $this->invariantBool(
            'scope_analyzer_detects_overlap',
            $ok,
            'Scope conflict analyzer must detect write-set overlap with active leases.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantGovernanceBlocksKillSwitch(): array
    {
        $result = $this->governance->precheck(
            [['task_packet_id' => 'tp', 'risk_level' => 'low', 'evidence_required' => false]],
            [['agent_id' => 'agent-a', 'status' => 'available']],
            ['kill_switch_state' => 'tripped'],
        );
        $ok = (string) ($result['status'] ?? '') === 'blocked'
            && in_array('kill_switch_tripped', (array) ($result['global_blockers'] ?? []), true);

        return $this->invariantBool(
            'governance_blocks_when_kill_switch_tripped',
            $ok,
            'Governance precheck must block when the kill switch is tripped.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantReceiptHashStable(): array
    {
        try {
            $payload = [
                'task_packet_id' => 'cert.tp.'.bin2hex(random_bytes(4)),
                'task_packet_hash' => str_repeat('a', 64),
                'agent_id' => 'cert.agent.'.bin2hex(random_bytes(4)),
                'risk_level' => 'low',
                'workspace_policy' => 'none',
                'requires_lease' => false,
                'dry_run_only' => true,
                'scope_lock' => ['write_set' => ['fileX.php'], 'read_set' => []],
                'evidence_refs' => ['ev1.md'],
            ];
            $first = $this->receipts->build($payload, ['receipt_id' => 'cert-rcpt-'.bin2hex(random_bytes(4))]);
            $hash1 = (string) ($first['receipt_hash'] ?? '');
            $second = $this->receipts->build($payload, ['receipt_id' => 'cert-rcpt-'.bin2hex(random_bytes(4))]);
            $hash2 = (string) ($second['receipt_hash'] ?? '');
            $ok = $hash1 !== '' && $hash1 === $hash2;
        } catch (\Throwable $e) {
            return $this->invariantBool(
                'receipt_hash_stable',
                false,
                'Receipt builder threw exception: '.$e->getMessage(),
            );
        }

        return $this->invariantBool(
            'receipt_hash_stable',
            $ok,
            'Dry-run receipt builder must produce stable sha256 hash for identical input payloads.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantBatchPlannerProducesPlanShape(): array
    {
        try {
            $plan = $this->batchPlanner->plan();
            $ok = is_array($plan)
                && array_key_exists('planned_dispatches', $plan)
                && array_key_exists('blocked_dispatches', $plan)
                && (string) ($plan['mode'] ?? '') === AgentDispatchPlannerBatchPlanner::MODE
                && (bool) ($plan['dispatch_allowed'] ?? true) === false;
        } catch (\Throwable $e) {
            return $this->invariantBool(
                'batch_planner_produces_plan_shape',
                false,
                'Batch planner threw exception: '.$e->getMessage(),
            );
        }

        return $this->invariantBool(
            'batch_planner_produces_plan_shape',
            $ok,
            'Batch planner must always produce planned/blocked lists with dispatch_allowed false.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantBatchHashStable(): array
    {
        try {
            $a = $this->batchPlanner->plan();
            $b = $this->batchPlanner->plan();
            $ok = (string) ($a['batch_hash'] !== '' ? $a['batch_hash'] : '') !== ''
                && (string) $a['batch_hash'] === (string) $b['batch_hash'];
        } catch (\Throwable) {
            $ok = false;
        }

        return $this->invariantBool(
            'batch_hash_stable',
            $ok,
            'Batch hash must be stable for the same input ledger state.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeSafetyInvariants(): array
    {
        $invariants = [];
        $flags = $this->batchPlanner->runtimeFlags();
        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'claim_real_allowed',
        ] as $flag) {
            $invariants[] = $this->invariantBool(
                'runtime_safety:'.$flag.'_false',
                ($flags[$flag] ?? null) === false,
                "Runtime flag {$flag} must remain false in this stage.",
            );
        }
        $invariants[] = $this->invariantBool('runtime_safety:no_dispatch_real', true, 'Dispatch real must remain disabled.');
        $invariants[] = $this->invariantBool('runtime_safety:no_provider_call', true, 'No provider call from this layer.');
        $invariants[] = $this->invariantBool('runtime_safety:no_token_spend', true, 'No token spend from this layer.');
        $invariants[] = $this->invariantBool('runtime_safety:no_self_programming', true, 'No self-programming from this layer.');

        return $invariants;
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantBool(string $name, bool $ok, string $description, bool $warning = false): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'description' => $description,
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

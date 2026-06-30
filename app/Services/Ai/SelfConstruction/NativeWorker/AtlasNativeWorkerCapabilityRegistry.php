<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Pure Atlas-native Worker Swarm capability map. Separates BOOTSTRAP external muscles
 * (external_provider_worker / human_operator) from the FINAL Atlas-native runtime capabilities that
 * the worker swarm owns end-to-end.
 *
 * INVARIANTS:
 *   - Deterministic ordering: capabilities() returns rows in a fixed, byte-identical sequence.
 *   - Each row carries {capability_id, autonomy_level, required_inputs, outputs, forbidden_side_effects, readiness_requirements}.
 *   - bootstrapOwners() lists ONLY ids flagged bootstrap_only — never marked as final runtime owners.
 *   - No duplicate capability_id across capabilities() ∪ bootstrapOwners().
 *   - NO scalar score / rank / readiness percentage anywhere — facts only.
 */
final class AtlasNativeWorkerCapabilityRegistry
{
    public const AUTONOMY_NATIVE_AUTONOMOUS = 'native_autonomous';

    public const AUTONOMY_NATIVE_SUPERVISED = 'native_supervised';

    public const AUTONOMY_BOOTSTRAP_ONLY = 'bootstrap_only';

    public const FINAL_CAPABILITY_IDS = [
        'inspect_task_packet',
        'prepare_patch_plan',
        'apply_scoped_patch',
        'run_gates',
        'write_evidence',
        'request_rollback',
        'learn_from_receipt',
    ];

    public const BOOTSTRAP_IDS = [
        'external_provider_worker',
        'human_operator',
    ];

    /**
     * @return list<array{capability_id:string, autonomy_level:string, required_inputs:list<string>, outputs:list<string>, forbidden_side_effects:list<string>, readiness_requirements:list<string>}>
     */
    public function capabilities(): array
    {
        return [
            $this->row('inspect_task_packet',
                self::AUTONOMY_NATIVE_AUTONOMOUS,
                ['task_packet_envelope'],
                ['parsed_task_facts', 'allowed_files_set', 'acceptance_criteria_list'],
                ['mutates_repo', 'calls_external_provider', 'invokes_shell'],
                ['task_packet_schema_loaded', 'allowed_files_oracle_present'],
            ),
            $this->row('prepare_patch_plan',
                self::AUTONOMY_NATIVE_AUTONOMOUS,
                ['parsed_task_facts', 'workspace_snapshot'],
                ['ordered_patch_plan', 'expected_evidence_keys'],
                ['mutates_repo', 'calls_external_provider', 'invokes_shell'],
                ['code_intelligence_index_fresh', 'workspace_snapshot_present'],
            ),
            $this->row('apply_scoped_patch',
                self::AUTONOMY_NATIVE_SUPERVISED,
                ['ordered_patch_plan', 'allowed_files_set', 'workspace_lock'],
                ['applied_patch_receipt', 'changed_files_set'],
                ['writes_outside_allowed_files', 'commits_without_evidence'],
                ['workspace_lock_acquired', 'scope_lock_verified'],
            ),
            $this->row('run_gates',
                self::AUTONOMY_NATIVE_AUTONOMOUS,
                ['applied_patch_receipt'],
                ['gate_verdict_set', 'evidence_refs_list'],
                ['skips_failing_gate', 'fabricates_green'],
                ['gate_registry_loaded', 'verification_commands_executable'],
            ),
            $this->row('write_evidence',
                self::AUTONOMY_NATIVE_AUTONOMOUS,
                ['gate_verdict_set', 'evidence_refs_list'],
                ['evidence_ledger_receipt'],
                ['overwrites_existing_evidence', 'silently_drops_facts'],
                ['evidence_ledger_writable', 'fact_schema_registered'],
            ),
            $this->row('request_rollback',
                self::AUTONOMY_NATIVE_SUPERVISED,
                ['gate_verdict_set', 'evidence_ledger_receipt'],
                ['rollback_plan', 'restoration_receipt'],
                ['rollbacks_without_evidence', 'force_pushes_main'],
                ['rollback_policy_loaded', 'merge_history_intact'],
            ),
            $this->row('learn_from_receipt',
                self::AUTONOMY_NATIVE_AUTONOMOUS,
                ['evidence_ledger_receipt'],
                ['learning_signal_recorded'],
                ['fabricates_compounding_signal', 'persists_score'],
                ['compounding_ledger_writable', 'receipt_schema_known'],
            ),
        ];
    }

    /**
     * @return list<array{capability_id:string, autonomy_level:string, required_inputs:list<string>, outputs:list<string>, forbidden_side_effects:list<string>, readiness_requirements:list<string>, bootstrap_only:true}>
     */
    public function bootstrapOwners(): array
    {
        return [
            array_merge($this->row('external_provider_worker',
                self::AUTONOMY_BOOTSTRAP_ONLY,
                ['task_packet_envelope', 'provider_credentials'],
                ['raw_provider_diff'],
                ['runs_in_final_atlas_native_runtime'],
                ['provider_quota_available'],
            ), ['bootstrap_only' => true]),
            array_merge($this->row('human_operator',
                self::AUTONOMY_BOOTSTRAP_ONLY,
                ['unresolved_question'],
                ['operator_decision'],
                ['embedded_in_runtime_loop', 'auto_approves_self_edit'],
                ['operator_available'],
            ), ['bootstrap_only' => true]),
        ];
    }

    /**
     * Match task packet needs to native worker capabilities and return ordered candidates.
     *
     * @param  array{required_capabilities?:list<string>, risk_ceiling?:string}  $taskNeeds
     *   required_capabilities: capability_ids the task declares it needs.
     *   risk_ceiling: AUTONOMY_* constant; capabilities with a higher-risk autonomy level are excluded.
     *                 Defaults to AUTONOMY_NATIVE_SUPERVISED (all native levels allowed).
     * @return array{candidates:list<array{capability_id:string,autonomy_level:string}>, unsupported_gap:list<string>}
     *   candidates: matched capabilities ordered autonomous-before-supervised then by canonical registry order.
     *   unsupported_gap: required_capabilities absent from the registry or above the risk ceiling.
     */
    public function route(array $taskNeeds): array
    {
        $required    = array_values(array_map('strval', (array) ($taskNeeds['required_capabilities'] ?? [])));
        $ceiling     = (string) ($taskNeeds['risk_ceiling'] ?? self::AUTONOMY_NATIVE_SUPERVISED);
        $ceilingRank = $this->autonomyRank($ceiling);

        $canonicalOrder = [];
        $byId           = [];
        foreach ($this->capabilities() as $idx => $cap) {
            $byId[$cap['capability_id']]            = $cap;
            $canonicalOrder[$cap['capability_id']]  = $idx;
        }

        $candidates     = [];
        $unsupportedGap = [];

        foreach ($required as $reqId) {
            if (! isset($byId[$reqId])) {
                $unsupportedGap[] = $reqId;
                continue;
            }
            $cap  = $byId[$reqId];
            $rank = $this->autonomyRank($cap['autonomy_level']);
            if ($rank > $ceilingRank) {
                $unsupportedGap[] = $reqId;
                continue;
            }
            $candidates[] = [
                'capability_id'  => $cap['capability_id'],
                'autonomy_level' => $cap['autonomy_level'],
                '_rank'          => $rank,
                '_order'         => $canonicalOrder[$reqId],
            ];
        }

        usort($candidates, static fn (array $a, array $b): int =>
            $a['_rank'] !== $b['_rank'] ? $a['_rank'] <=> $b['_rank'] : $a['_order'] <=> $b['_order']
        );

        return [
            'candidates'      => array_values(array_map(static function (array $c): array {
                unset($c['_rank'], $c['_order']);
                return $c;
            }, $candidates)),
            'unsupported_gap' => array_values(array_unique($unsupportedGap)),
        ];
    }

    private function autonomyRank(string $level): int
    {
        return match ($level) {
            self::AUTONOMY_NATIVE_AUTONOMOUS => 0,
            self::AUTONOMY_NATIVE_SUPERVISED => 1,
            self::AUTONOMY_BOOTSTRAP_ONLY    => 2,
            default                          => 99,
        };
    }

    /**
     * @return list<string>  every capability_id (final + bootstrap) — guaranteed unique.
     */
    public function allIds(): array
    {
        $ids = [];
        foreach (array_merge($this->capabilities(), $this->bootstrapOwners()) as $row) {
            $ids[] = $row['capability_id'];
        }

        return $ids;
    }

    /**
     * @return array{capability_id:string, autonomy_level:string, required_inputs:list<string>, outputs:list<string>, forbidden_side_effects:list<string>, readiness_requirements:list<string>}
     */
    private function row(string $id, string $autonomy, array $inputs, array $outputs, array $forbidden, array $readiness): array
    {
        return [
            'capability_id' => $id,
            'autonomy_level' => $autonomy,
            'required_inputs' => array_values(array_map('strval', $inputs)),
            'outputs' => array_values(array_map('strval', $outputs)),
            'forbidden_side_effects' => array_values(array_map('strval', $forbidden)),
            'readiness_requirements' => array_values(array_map('strval', $readiness)),
        ];
    }
}

<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Agent Control Plane Certification Output Map v1 doc.
 *
 * The doc is a FIELD MAP for the three read-only certification commands
 * (--agent-control-plane / --…-chain-integrity-certification-status /
 * --…-deterministic-chain-replay-status). Its load-bearing contract is the
 * "Padrao tudo verde" section plus the "Regras para IA". An execution is
 * "tudo verde" ONLY when ALL of these hold simultaneously:
 *   - status ∈ {agent_control_plane_ready, available};
 *   - mode starts with "read_only_";
 *   - every *_allowed flag is false (execution/completion/dispatch/
 *     claim_persisted/ledger_write/runtime_write);
 *   - invariants_all_true = true;
 *   - runtime_safety_all_false = true;
 *   - violation_count = 0 (warning_count ideally 0 — non-blocking);
 *   - current_* equals expected_* (slice/pointer);
 *   - non_execution_guarantees[] has >= 1 item;
 *   - replay/deterministic/proof hashes did not drift since baseline
 *     without a code change.
 * Any deviation forces diagnosis before advancing.
 *
 * The doc's "Regras para IA" are enforced as hard invariants here:
 *   - runtime_safety_all_false=true is read as "all runtime flags are
 *     false" (SAFE), NEVER as "runtime is authorized";
 *   - a current_ vs expected_ pointer mismatch is a regression, never
 *     silenced;
 *   - a hash that drifted with no code change is flagged, never reported
 *     as "expected".
 *
 * This service is PURE and deterministic, with no DB and no I/O. It never
 * promotes the projection to writer/runtime — it only reads the JSON the
 * three read-only commands emit and decides whether the operator may proceed.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
 */
final class AtlasAgentControlPlaneCertificationOutputMapService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.agent_control_plane_certification_output_map.v1';

    public const MODE = 'read_only_certification_output_map';

    /** Canonical command keys for the three read-only certifications. */
    public const COMMAND_PROJECTION = 'agent_control_plane';

    public const COMMAND_CHAIN_INTEGRITY = 'agent_control_plane_chain_integrity_certification_status';

    public const COMMAND_REPLAY = 'agent_control_plane_deterministic_chain_replay_status';

    /** schema_version each command must report (from the "Contratos" table). */
    public const CONTRACT_SCHEMA_VERSIONS = [
        self::COMMAND_PROJECTION => 'atlas.self_construction_agent_control_plane.v1',
        self::COMMAND_CHAIN_INTEGRITY => 'atlas.self_construction_agent_control_plane_chain_integrity_certification_status.v1',
        self::COMMAND_REPLAY => 'atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1',
    ];

    /** status values the "tudo verde" pattern accepts. */
    public const ACCEPTABLE_STATUSES = ['agent_control_plane_ready', 'available'];

    /** Every mode must start with this prefix or the projection regressed to writer. */
    public const READ_ONLY_MODE_PREFIX = 'read_only_';

    /**
     * The *_allowed flags the doc demands stay false. Each may or may not be
     * present per command, but if present it MUST be false.
     */
    public const MUST_BE_FALSE_FLAGS = [
        'execution_allowed',
        'completion_allowed',
        'dispatch_allowed',
        'claim_persisted',
        'ledger_write_allowed',
        'runtime_write_allowed',
    ];

    /**
     * The two conceptual gates the doc says must stay false while the OS is
     * "building" (promotion gate / runtime pilot completion). If present, false.
     */
    public const FORBIDDEN_WHILE_BUILDING_FLAGS = [
        'promotion_allowed',
        'completion_claim_allowed',
    ];

    /** Hash fields whose drift without a code change is a determinism bug. */
    public const STABLE_HASH_FIELDS = [
        'replay_hash',
        'deterministic_replay_hash',
        'proof_bundle_hash',
    ];

    /**
     * Evaluate one read-only execution against the full "tudo verde" rule set.
     * Returns whether the execution is all-green plus every concrete deviation.
     * PURE — no I/O, no DB. The projection is NEVER promoted here.
     *
     * @param  array<string,mixed>  $fields  the JSON the command emitted (flat or
     *                                        with the per-command nested block already merged in)
     * @return array{
     *   command:string,
     *   all_green:bool,
     *   deviations:array<int,string>,
     *   checks:array<string,bool>,
     *   runtime_authorized:bool,
     *   human_label:string
     * }
     */
    public function evaluate(string $command, array $fields): array
    {
        $deviations = [];
        $checks = [];

        // 1. status ∈ acceptable set.
        $status = $this->stringField($fields, 'status');
        $checks['status_acceptable'] = in_array($status, self::ACCEPTABLE_STATUSES, true);
        if (! $checks['status_acceptable']) {
            $deviations[] = 'status_not_acceptable:'.($status === '' ? 'missing' : $status);
        }

        // 2. mode starts with read_only_.
        $mode = $this->stringField($fields, 'mode');
        $checks['mode_read_only'] = $mode !== '' && str_starts_with($mode, self::READ_ONLY_MODE_PREFIX);
        if (! $checks['mode_read_only']) {
            $deviations[] = 'mode_regressed_to_writer:'.($mode === '' ? 'missing' : $mode);
        }

        // 3. every present *_allowed flag is false.
        $checks['all_allowed_flags_false'] = true;
        foreach (self::MUST_BE_FALSE_FLAGS as $flag) {
            if (array_key_exists($flag, $fields) && $fields[$flag] !== false) {
                $checks['all_allowed_flags_false'] = false;
                $deviations[] = 'flag_must_be_false:'.$flag;
            }
        }

        // 3b. conceptual gates, if surfaced, must also be false.
        foreach (self::FORBIDDEN_WHILE_BUILDING_FLAGS as $flag) {
            if (array_key_exists($flag, $fields) && $fields[$flag] !== false) {
                $checks['all_allowed_flags_false'] = false;
                $deviations[] = 'forbidden_while_building:'.$flag;
            }
        }

        // 4. non_execution_guarantees[] exists and has >= 1 item.
        $guarantees = $fields['non_execution_guarantees'] ?? null;
        $checks['has_non_execution_guarantees'] = is_array($guarantees) && count($guarantees) >= 1;
        if (! $checks['has_non_execution_guarantees']) {
            $deviations[] = 'non_execution_guarantees_empty_or_missing';
        }

        // 5. invariants_all_true=true (only meaningful where present).
        if (array_key_exists('invariants_all_true', $fields)) {
            $checks['invariants_all_true'] = $fields['invariants_all_true'] === true;
            if (! $checks['invariants_all_true']) {
                $deviations[] = 'invariant_violated:invariants_all_true_is_not_true';
            }
        }

        // 6. runtime_safety_all_false=true (SAFE state). Regras para IA: this is
        //    NOT runtime authorization — it means every runtime flag is false.
        if (array_key_exists('runtime_safety_all_false', $fields)) {
            $checks['runtime_safety_all_false'] = $fields['runtime_safety_all_false'] === true;
            if (! $checks['runtime_safety_all_false']) {
                $deviations[] = 'runtime_safety_not_all_false';
            }
        }

        // 7. violation_count=0 (hard); warning_count ideally 0 (non-blocking).
        if (array_key_exists('violation_count', $fields)) {
            $violations = (int) $fields['violation_count'];
            $checks['no_violations'] = $violations === 0;
            if ($violations !== 0) {
                $deviations[] = 'violation_count_blocks_promotion:'.$violations;
            }
        }
        if (array_key_exists('warning_count', $fields)) {
            // Warnings are recorded but do NOT block all-green.
            $checks['no_warnings'] = ((int) $fields['warning_count']) === 0;
        }

        // 8. current_* equals expected_* (slice + pointer). Regras para IA:
        //    a mismatch is a regression and must never be silenced.
        foreach ($this->pointerPairs($fields) as $pair) {
            [$label, $current, $expected] = $pair;
            $aligned = $current === $expected;
            $checks['pointer_aligned:'.$label] = $aligned;
            if (! $aligned) {
                $deviations[] = 'pointer_regression:'.$label.':current='.$current.':expected='.$expected;
            }
        }

        $allGreen = $deviations === [];

        return [
            'command' => $command,
            'all_green' => $allGreen,
            'deviations' => $deviations,
            'checks' => $checks,
            // Regras para IA: the projection NEVER authorizes runtime, all-green or not.
            'runtime_authorized' => false,
            'human_label' => $allGreen
                ? 'all_green_read_only_safe_may_proceed'
                : 'deviation_detected_diagnose_before_advancing',
        ];
    }

    /**
     * Compare a fresh hash reading against a recorded baseline. Regras para IA:
     * a hash that drifted with no code change is a determinism bug and must be
     * flagged — never reported as "expected". When code DID change, drift is
     * acknowledged (still recorded, but allowed). PURE.
     *
     * @param  array<string,string>  $baseline   field => previously recorded hash
     * @param  array<string,string>  $current    field => freshly observed hash
     * @return array{
     *   stable:bool,
     *   drifted_fields:array<int,string>,
     *   code_changed:bool,
     *   verdict:string
     * }
     */
    public function compareHashes(array $baseline, array $current, bool $codeChanged = false): array
    {
        $drifted = [];

        foreach (self::STABLE_HASH_FIELDS as $field) {
            $before = $baseline[$field] ?? null;
            $after = $current[$field] ?? null;

            // Only compare fields present on both sides; a missing reading is not drift.
            if ($before === null || $after === null) {
                continue;
            }
            if ($before !== $after) {
                $drifted[] = $field;
            }
        }

        $stable = $drifted === [];

        $verdict = match (true) {
            $stable => 'hashes_stable_deterministic',
            // Doc: drift with no code change is NEVER "expected" — it is a bug.
            ! $codeChanged => 'determinism_bug_hash_drift_without_code_change',
            default => 'hash_drift_acknowledged_code_changed_record_release_note',
        };

        return [
            'stable' => $stable,
            'drifted_fields' => $drifted,
            'code_changed' => $codeChanged,
            'verdict' => $verdict,
        ];
    }

    /**
     * Confirm a command reports the exact schema_version the "Contratos" table
     * pins. A mismatch means the field map must be re-read before trust. PURE.
     *
     * @return array{command:string, expected:string, observed:string, matches:bool}
     */
    public function verifySchemaVersion(string $command, string $observedSchemaVersion): array
    {
        $expected = self::CONTRACT_SCHEMA_VERSIONS[$command] ?? '';

        return [
            'command' => $command,
            'expected' => $expected,
            'observed' => $observedSchemaVersion,
            'matches' => $expected !== '' && $expected === $observedSchemaVersion,
        ];
    }

    /**
     * Roll the three per-command evaluations into one corridor verdict. The
     * corridor is all-green ONLY when all three executions are individually
     * all-green. Runtime is never authorized regardless of result. PURE.
     *
     * @param  array<int, array{command:string, all_green:bool, deviations:array<int,string>}>  $evaluations
     * @return array{
     *   schema_version:string,
     *   mode:string,
     *   evaluated_command_count:int,
     *   all_green_command_count:int,
     *   corridor_all_green:bool,
     *   runtime_authorized:bool,
     *   blocking_commands:array<int,string>
     * }
     */
    public function corridorVerdict(array $evaluations): array
    {
        $allGreenCount = 0;
        $blocking = [];

        foreach ($evaluations as $evaluation) {
            if (($evaluation['all_green'] ?? false) === true) {
                $allGreenCount++;

                continue;
            }
            $blocking[] = (string) ($evaluation['command'] ?? 'unknown');
        }

        $count = count($evaluations);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'evaluated_command_count' => $count,
            'all_green_command_count' => $allGreenCount,
            'corridor_all_green' => $count > 0 && $allGreenCount === $count,
            // Regras para IA: the corridor is read-only; it never authorizes runtime.
            'runtime_authorized' => false,
            'blocking_commands' => $blocking,
        ];
    }

    /**
     * Default safe self-description: the canonical field-map metadata with a
     * worked "tudo verde" example so the command has a deterministic payload.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        $sample = $this->allGreenSample(self::COMMAND_CHAIN_INTEGRITY);
        $evaluation = $this->evaluate(self::COMMAND_CHAIN_INTEGRITY, $sample);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'commands' => array_keys(self::CONTRACT_SCHEMA_VERSIONS),
            'contract_schema_versions' => self::CONTRACT_SCHEMA_VERSIONS,
            'acceptable_statuses' => self::ACCEPTABLE_STATUSES,
            'read_only_mode_prefix' => self::READ_ONLY_MODE_PREFIX,
            'must_be_false_flags' => self::MUST_BE_FALSE_FLAGS,
            'stable_hash_fields' => self::STABLE_HASH_FIELDS,
            'runtime_authorized' => false,
            'sample_evaluation' => $evaluation,
            'regras_para_ia' => [
                'runtime_safety_all_false_true_means_all_runtime_flags_false_not_runtime_authorized',
                'hash_drift_without_code_change_is_a_bug_never_expected',
                'current_expected_pointer_mismatch_is_a_regression_never_silenced',
                'new_field_must_cite_exact_contract_schema_version',
            ],
        ];
    }

    /**
     * Build a fully-aligned "tudo verde" field set for a command, used by
     * describe() and tests as the canonical green baseline.
     *
     * @return array<string,mixed>
     */
    public function allGreenSample(string $command): array
    {
        $base = [
            'status' => $command === self::COMMAND_PROJECTION ? 'agent_control_plane_ready' : 'available',
            'mode' => self::READ_ONLY_MODE_PREFIX.$command,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'non_execution_guarantees' => [
                'no_adapter_execution',
                'no_agent_dispatch',
                'no_ledger_write',
                'no_runtime_write',
                'no_completion_claim',
            ],
        ];

        if ($command === self::COMMAND_PROJECTION) {
            $base['completion_allowed'] = false;
            $base['claim_persisted'] = false;

            return $base;
        }

        // Both certification commands carry runtime_write + integrity counters.
        $base['runtime_write_allowed'] = false;
        $base['invariants_all_true'] = true;
        $base['runtime_safety_all_false'] = true;
        $base['violation_count'] = 0;
        $base['warning_count'] = 0;

        if ($command === self::COMMAND_CHAIN_INTEGRITY) {
            $base['current_next_required_slice'] = 'activate_signed_one_shot_scheduler_tick';
            $base['expected_next_required_slice'] = 'activate_signed_one_shot_scheduler_tick';

            return $base;
        }

        // replay
        $base['current_pointer'] = 'activate_signed_one_shot_scheduler_tick';
        $base['expected_pointer'] = 'activate_signed_one_shot_scheduler_tick';

        return $base;
    }

    /**
     * Extract the current/expected pointer pairs that exist in the field set.
     *
     * @param  array<string,mixed>  $fields
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function pointerPairs(array $fields): array
    {
        $candidates = [
            'next_required_slice' => ['current_next_required_slice', 'expected_next_required_slice'],
            'pointer' => ['current_pointer', 'expected_pointer'],
        ];

        $pairs = [];
        foreach ($candidates as $label => [$currentKey, $expectedKey]) {
            if (array_key_exists($currentKey, $fields) && array_key_exists($expectedKey, $fields)) {
                $pairs[] = [
                    $label,
                    $this->stringField($fields, $currentKey),
                    $this->stringField($fields, $expectedKey),
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string,mixed>  $fields
     */
    private function stringField(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}

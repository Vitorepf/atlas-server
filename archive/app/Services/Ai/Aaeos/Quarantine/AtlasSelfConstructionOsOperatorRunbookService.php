<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction OS - Operator Runbook v1 — runtime.
 *
 * Turns the operator-facing runbook doc into deterministic, pure decision logic
 * the operator (or an automated pre-flight) can call instead of re-reading the
 * prose. The doc does NOT authorize runtime; neither does this service. It
 * enforces the documented stop signals so a sprint cannot proceed past a red
 * gate. Every method is a pure function of its arguments — no DB, no IO, no
 * provider calls.
 *
 * Rules enforced (section numbers refer to the doc):
 *
 *  - Pre macro-sprint checklist (section 5): seven boolean gates. If any item is
 *    red the sprint does NOT start; a remediation slice is opened instead. All
 *    green is the only "may start".
 *  - Post macro-sprint checklist (section 6): a sprint may not be declared
 *    complete while any post-check is red — new unaccepted violations, unowned
 *    new warnings, runtime_safety_all_false flipped, or a missing release
 *    dossier evidence path each force a rollback of the scope-lock.
 *  - next_required_slice reading (section 7): it must point to exactly ONE
 *    slice. Empty => stop (not a green light). More than one => fan-out that
 *    must be collapsed. Changed without an evidence delta => drift, do not act.
 *  - runtime_safety_all_false reading (section 8): while true the posture is
 *    projection/dry-run and provider dispatch is disabled. If the flag is true
 *    and an output suggests runtime, the OUTPUT is wrong — trust the flag.
 *  - Violations vs warnings (section 9): violations block promotion / pilot /
 *    scope-lock release. Warnings do not block, but a warning past its deadline
 *    GRADUATES to a violation, and a sprint may never close with an unowned
 *    warning.
 *  - Stop-and-ask conditions (section 10): seven unconditional stop triggers
 *    that force a human review packet.
 *  - Pre-runtime gate (section 11): even after every surface is green, six facts
 *    must ALL hold before the operator may recommend flipping any runtime
 *    authorization. One red => runtime stays disabled, no fast path.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
 */
final class AtlasSelfConstructionOsOperatorRunbookService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_runbook.v1';

    public const MODE = 'read_only_operator_runbook_decider';

    /**
     * The five surfaces and the single question each one proves (section 3).
     * They are explicitly NOT interchangeable; none executes a real provider.
     *
     * @var array<string,string>
     */
    public const SURFACES = [
        'certification' => 'does the projection contract hold under scenarios, fuzz, mutation and coverage',
        'replay' => 'given the same input ledger, does the projection produce the same authoritative snapshot',
        'workbench' => 'can an operator inspect a single packet, lock, run, liveness or continuation state safely',
        'observatory' => 'what is the longitudinal posture of the projection across time',
        'runtime_pilot' => 'what would runtime look like if we built it (dry-run only, gated by Runtime Pilot Certification)',
    ];

    /**
     * Pre macro-sprint checklist gates (section 5). All must be true to start.
     *
     * @var list<string>
     */
    public const PRE_SPRINT_GATES = [
        'canonical_docs_clean',
        'docs_health_ok',
        'certification_batch_recorded',
        'replay_diff_explained',
        'runtime_safety_all_false',
        'no_conflicting_active_claim_or_lease',
        'next_required_slice_one_ahead',
    ];

    /**
     * Post macro-sprint checklist gates (section 6). All must be true to close.
     *
     * @var list<string>
     */
    public const POST_SPRINT_GATES = [
        'docs_health_rerun_ok',
        'certification_and_replay_rerun_ok',
        'new_violations_zero_or_accepted',
        'new_warnings_owned_and_tracked',
        'runtime_safety_all_false',
        'next_required_slice_updated',
        'release_dossier_evidence_recorded',
    ];

    /**
     * Unconditional stop-and-ask triggers (section 10). Any true => stop.
     *
     * @var list<string>
     */
    public const STOP_CONDITIONS = [
        'violation_references_runtime_authorization_or_scope_lock',
        'runtime_safety_all_false_flipped',
        'replay_diff_unexplained',
        'next_required_slice_empty_or_contradictory',
        'release_dossier_missing_evidence_for_available_slice',
        'certification_fuzz_new_untriaged_mutation',
        'two_slices_in_flight_same_axis',
    ];

    /**
     * Pre-runtime gate facts (section 11). All must hold to recommend a flip.
     *
     * @var list<string>
     */
    public const PRE_RUNTIME_GATES = [
        'all_safety_invariants_durable_and_monitored',
        'all_dry_run_surfaces_certified_no_untriaged_failure',
        'runtime_pilot_certification_no_open_violations',
        'self_programming_safety_contract_accepted_in_receipt',
        'forge_workspace_kill_switch_rollback_scope_lock_exercisable',
        'human_review_packet_signed',
    ];

    /**
     * Fields a human review packet must include when a stop fires (section 10).
     *
     * @var list<string>
     */
    public const HUMAN_REVIEW_PACKET_FIELDS = [
        'snapshot_id',
        'evidence_ledger_ref',
        'replay_diff_ref',
        'certification_batch_id',
        'violations',
        'warnings',
        'requested_decision',
        'rollback_plan',
    ];

    /**
     * Evaluate the pre macro-sprint checklist (section 5).
     *
     * Any red gate blocks the sprint; the documented response is to open a
     * remediation slice rather than start.
     *
     * @param  array<string,bool>  $gates  subset of PRE_SPRINT_GATES facts
     * @return array{may_start:bool,verdict:string,red_gates:list<string>,missing_gates:list<string>,directive:string}
     */
    public function evaluatePreSprint(array $gates): array
    {
        $red = [];
        $missing = [];

        foreach (self::PRE_SPRINT_GATES as $gate) {
            if (! array_key_exists($gate, $gates)) {
                $missing[] = $gate;

                continue;
            }
            if ($gates[$gate] !== true) {
                $red[] = $gate;
            }
        }

        // A missing fact cannot be asserted green, so it blocks too (no
        // "looks fine as evidence", section 13).
        $blocked = $red !== [] || $missing !== [];

        return [
            'may_start' => ! $blocked,
            'verdict' => $blocked ? 'blocked' : 'may_start',
            'red_gates' => $red,
            'missing_gates' => $missing,
            'directive' => $blocked
                ? 'open_remediation_slice'
                : 'sprint_may_open_single_axis',
        ];
    }

    /**
     * Evaluate the post macro-sprint checklist (section 6).
     *
     * If any post-check is red the sprint is NOT complete; the documented
     * response is to roll back the scope-lock and open a repair slice.
     *
     * @param  array<string,bool>  $gates  subset of POST_SPRINT_GATES facts
     * @return array{complete:bool,verdict:string,red_gates:list<string>,missing_gates:list<string>,directive:string}
     */
    public function evaluatePostSprint(array $gates): array
    {
        $red = [];
        $missing = [];

        foreach (self::POST_SPRINT_GATES as $gate) {
            if (! array_key_exists($gate, $gates)) {
                $missing[] = $gate;

                continue;
            }
            if ($gates[$gate] !== true) {
                $red[] = $gate;
            }
        }

        $blocked = $red !== [] || $missing !== [];

        return [
            'complete' => ! $blocked,
            'verdict' => $blocked ? 'rollback_required' : 'sprint_complete',
            'red_gates' => $red,
            'missing_gates' => $missing,
            'directive' => $blocked
                ? 'rollback_scope_lock_and_open_repair_slice'
                : 'close_sprint',
        ];
    }

    /**
     * Read next_required_slice as the canonical stop signal (section 7).
     *
     * - Exactly one slice => proceed.
     * - Empty list => STOP (not a green light).
     * - More than one => fan-out that must be collapsed before work starts.
     * - Changed without an evidence delta => drift; do not act on it.
     *
     * @param  list<string>  $slices            slices the projection reports
     * @param  bool          $changedSinceLast  did the value change vs last read
     * @param  bool          $evidenceDelta     is the change backed by new evidence
     * @return array{actionable:bool,status:string,slice:?string,count:int,directive:string}
     */
    public function readNextRequiredSlice(array $slices, bool $changedSinceLast = false, bool $evidenceDelta = true): array
    {
        $slices = array_values(array_filter($slices, static fn ($s): bool => is_string($s) && trim($s) !== ''));
        $count = count($slices);

        if ($count === 0) {
            return [
                'actionable' => false,
                'status' => 'empty_stop',
                'slice' => null,
                'count' => 0,
                'directive' => 'stop_projection_cannot_recommend_next_step',
            ];
        }

        if ($count > 1) {
            return [
                'actionable' => false,
                'status' => 'fan_out',
                'slice' => null,
                'count' => $count,
                'directive' => 'collapse_fan_out_to_single_slice_before_work',
            ];
        }

        // Exactly one slice, but a change with no evidence delta is drift.
        if ($changedSinceLast && ! $evidenceDelta) {
            return [
                'actionable' => false,
                'status' => 'drift',
                'slice' => $slices[0],
                'count' => 1,
                'directive' => 'treat_as_drift_do_not_act_until_evidence_supports',
            ];
        }

        return [
            'actionable' => true,
            'status' => 'single_slice',
            'slice' => $slices[0],
            'count' => 1,
            'directive' => 'proceed_to_slice_work_single_axis',
        ];
    }

    /**
     * Read runtime_safety_all_false (section 8).
     *
     * While the flag is true the posture is projection/dry-run and provider
     * dispatch is disabled. Operator rule: if the flag is true and an output
     * suggests runtime, the OUTPUT is wrong — trust the flag.
     *
     * @return array{posture:string,provider_dispatch_enabled:bool,output_suggests_runtime_conflict:bool,trusted_source:string,directive:string}
     */
    public function readRuntimeSafetyAllFalse(bool $flagTrue, bool $outputSuggestsRuntime = false): array
    {
        if ($flagTrue) {
            $conflict = $outputSuggestsRuntime;

            return [
                'posture' => 'projection_dry_run',
                'provider_dispatch_enabled' => false,
                'output_suggests_runtime_conflict' => $conflict,
                // The flag is canonical; a runtime-suggesting output is the wrong one.
                'trusted_source' => 'runtime_safety_all_false_flag',
                'directive' => $conflict
                    ? 'output_is_wrong_trust_the_flag_keep_dispatch_disabled'
                    : 'remain_in_projection_dry_run_posture',
            ];
        }

        // Flag is false — this is never a side effect of a feature slice; it is
        // its own macro-sprint with a signed receipt and human review packet.
        return [
            'posture' => 'flag_false_requires_dedicated_sprint',
            'provider_dispatch_enabled' => false,
            'output_suggests_runtime_conflict' => false,
            'trusted_source' => 'runtime_safety_all_false_flag',
            'directive' => 'flip_requires_separate_macro_sprint_with_signed_receipt_and_human_review',
        ];
    }

    /**
     * Classify the violations/warnings streams (section 9).
     *
     * Violations are hard-law breaches: they block promotion, runtime-pilot
     * enablement and scope-lock release. Warnings do not block, BUT a warning
     * whose age exceeds its deadline GRADUATES to a violation, and a sprint may
     * never close with an unowned warning.
     *
     * @param  list<string>  $violations  open violation identifiers
     * @param  list<array{id:string,owner?:string,age_days?:int,deadline_days?:int}>  $warnings
     * @return array{blocks_promotion:bool,blocks_runtime_pilot:bool,blocks_scope_lock_release:bool,effective_violations:list<string>,graduated_warnings:list<string>,unowned_warnings:list<string>,may_close_sprint:bool,directive:string}
     */
    public function classifyViolationsAndWarnings(array $violations, array $warnings = []): array
    {
        $graduated = [];
        $unowned = [];

        foreach ($warnings as $warning) {
            $id = (string) ($warning['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $owner = trim((string) ($warning['owner'] ?? ''));
            if ($owner === '') {
                $unowned[] = $id;
            }

            $age = (int) ($warning['age_days'] ?? 0);
            $deadline = (int) ($warning['deadline_days'] ?? 0);
            // "Warnings older than the deadline graduate to violations."
            if ($deadline > 0 && $age > $deadline) {
                $graduated[] = $id;
            }
        }

        $effective = array_values(array_unique([...$violations, ...$graduated]));
        $hasViolation = $effective !== [];

        // Never close a sprint with unowned warnings (section 9) or any
        // effective violation.
        $mayClose = ! $hasViolation && $unowned === [];

        return [
            'blocks_promotion' => $hasViolation,
            'blocks_runtime_pilot' => $hasViolation,
            'blocks_scope_lock_release' => $hasViolation,
            'effective_violations' => $effective,
            'graduated_warnings' => $graduated,
            'unowned_warnings' => $unowned,
            'may_close_sprint' => $mayClose,
            'directive' => $hasViolation
                ? 'remediate_in_same_sprint_or_rollback'
                : ($unowned === []
                    ? 'warnings_tracked_sprint_may_close'
                    : 'assign_owner_target_slice_and_deadline_before_close'),
        ];
    }

    /**
     * Evaluate the unconditional stop-and-ask conditions (section 10).
     *
     * If any trigger is true the operator stops and assembles a human review
     * packet with the eight required fields.
     *
     * @param  array<string,bool>  $signals  subset of STOP_CONDITIONS facts
     * @return array{must_stop:bool,verdict:string,triggered:list<string>,requires_human_review:bool,human_review_packet_fields:list<string>,directive:string}
     */
    public function evaluateStopConditions(array $signals): array
    {
        $triggered = [];

        foreach (self::STOP_CONDITIONS as $condition) {
            if (($signals[$condition] ?? false) === true) {
                $triggered[] = $condition;
            }
        }

        $mustStop = $triggered !== [];

        return [
            'must_stop' => $mustStop,
            'verdict' => $mustStop ? 'stop_unconditional' : 'no_stop_condition',
            'triggered' => $triggered,
            'requires_human_review' => $mustStop,
            'human_review_packet_fields' => $mustStop ? self::HUMAN_REVIEW_PACKET_FIELDS : [],
            'directive' => $mustStop
                ? 'stop_and_assemble_human_review_packet'
                : 'continue_daily_operator_flow',
        ];
    }

    /**
     * Evaluate the pre-runtime gate (section 11).
     *
     * Even after every surface is green, ALL six facts must hold before the
     * operator may recommend flipping any runtime authorization. One red =>
     * runtime stays disabled; there is no fast path.
     *
     * @param  array<string,bool>  $gates  subset of PRE_RUNTIME_GATES facts
     * @return array{may_recommend_runtime_flip:bool,verdict:string,red_gates:list<string>,missing_gates:list<string>,fast_path_available:bool,directive:string}
     */
    public function evaluatePreRuntimeGate(array $gates): array
    {
        $red = [];
        $missing = [];

        foreach (self::PRE_RUNTIME_GATES as $gate) {
            if (! array_key_exists($gate, $gates)) {
                $missing[] = $gate;

                continue;
            }
            if ($gates[$gate] !== true) {
                $red[] = $gate;
            }
        }

        $blocked = $red !== [] || $missing !== [];

        return [
            'may_recommend_runtime_flip' => ! $blocked,
            'verdict' => $blocked ? 'runtime_stays_disabled' : 'may_recommend_runtime_flip',
            'red_gates' => $red,
            'missing_gates' => $missing,
            // The doc is explicit: "There is no fast path."
            'fast_path_available' => false,
            'directive' => $blocked
                ? 'runtime_disabled_close_remaining_gates_no_fast_path'
                : 'all_gates_green_runtime_flip_may_be_recommended_with_signed_packet',
        ];
    }

    /**
     * Resolve which single surface answers a given question (section 3).
     *
     * Surfaces are not interchangeable; if a surface cannot answer its own
     * question the operator stops rather than relabelling a surface.
     *
     * @return array{surface:?string,question:?string,resolved:bool,directive:string}
     */
    public function resolveSurface(string $surface): array
    {
        $key = strtolower(trim($surface));

        if (! array_key_exists($key, self::SURFACES)) {
            return [
                'surface' => null,
                'question' => null,
                'resolved' => false,
                'directive' => 'unknown_surface_do_not_relabel_stop',
            ];
        }

        return [
            'surface' => $key,
            'question' => self::SURFACES[$key],
            'resolved' => true,
            'directive' => 'open_named_surface_run_projection_command',
        ];
    }

    /**
     * Full default posture snapshot — the primary entry point.
     *
     * Returns the static governance shape (surfaces, gate lists, stop
     * conditions, pre-runtime gates) together with the invariant the doc never
     * relaxes: this runbook authorizes no runtime, and provider dispatch stays
     * disabled while runtime_safety_all_false is true.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'authorizes_runtime' => false,
            'provider_dispatch_enabled' => false,
            'runtime_safety_all_false_expected' => true,
            'surfaces' => self::SURFACES,
            'pre_sprint_gates' => self::PRE_SPRINT_GATES,
            'post_sprint_gates' => self::POST_SPRINT_GATES,
            'stop_conditions' => self::STOP_CONDITIONS,
            'pre_runtime_gates' => self::PRE_RUNTIME_GATES,
            'human_review_packet_fields' => self::HUMAN_REVIEW_PACKET_FIELDS,
            'next_required_slice_is_canonical_stop_signal' => true,
            'daily_flow' => [
                'read snapshot id, runtime_safety_all_false, next_required_slice',
                'open the matching surface (cert/replay/workbench/observatory/pilot)',
                'run the projection command, capture output',
                'compare to last green evidence',
                'if delta is expected and documented -> proceed to slice work',
                'if delta is unexpected -> stop, open repair slice',
                'close slice with evidence, update next_required_slice',
                'run docs-health, certification, replay',
                'if all green -> close sprint',
                'if any red -> rollback scope-lock, open repair slice',
            ],
        ];
    }
}

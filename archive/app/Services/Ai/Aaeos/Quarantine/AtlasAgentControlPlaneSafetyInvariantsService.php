<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Agent Control Plane Safety Invariants v1 doc.
 *
 * The doc defines exactly SIXTEEN hard-law invariants (I-01..I-16). Each is a
 * STOP CONDITION, not a guideline. The load-bearing contract:
 *
 *   - "Any violation downgrades posture and blocks promotion. There is no fast
 *     path around them."
 *   - "If an invariant cannot be satisfied by evidence, treat it as violated.
 *     'Looks fine' is not evidence." → an invariant with missing/empty
 *     evidence is VIOLATED, never assumed-green.
 *   - "No combination of features may relax an invariant." / "An invariant
 *     cannot be 'temporarily relaxed'. If a slice cannot satisfy it, the slice
 *     does not ship." → a temporary-waiver / relax flag NEVER clears a
 *     violation.
 *   - I-06 (no self-programming): "Self-programming is explicitly disabled …
 *     non-negotiable." A present write surface over Atlas code is a hard
 *     violation regardless of any other signal.
 *   - "Strengthening an invariant is allowed. Relaxing one is not."
 *
 * This service is PURE and deterministic: no DB, no I/O. It reads a slice's
 * declared evidence map and decides whether the slice may ship and whether
 * the posture stays green. It never authorizes runtime, never dispatches,
 * never spends tokens, never enables self-programming.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
 */
final class AtlasAgentControlPlaneSafetyInvariantsService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.agent_control_plane_safety_invariants.v1';

    public const MODE = 'read_only_safety_invariants_evaluation';

    /** The doc lists exactly this many hard-law invariants. */
    public const INVARIANT_COUNT = 16;

    public const POSTURE_GREEN = 'green';

    public const POSTURE_DOWNGRADED = 'downgraded';

    /**
     * The 16 invariants exactly as the doc enumerates them. `evidence` is the
     * canonical evidence key the doc names as satisfying that invariant; a
     * slice must present a non-empty value under that key (the ledger-reachable
     * proof) or the invariant is treated as violated.
     *
     * @var array<int, array{id:string,name:string,evidence:string,hard_law:bool}>
     */
    private const INVARIANTS = [
        ['id' => 'I-01', 'name' => 'No provider call before authorization', 'evidence' => 'authorization_gate', 'hard_law' => true],
        ['id' => 'I-02', 'name' => 'No token spend before budget gate', 'evidence' => 'budget_gate', 'hard_law' => true],
        ['id' => 'I-03', 'name' => 'No adapter execution before guard', 'evidence' => 'adapter_execution_guard', 'hard_law' => true],
        ['id' => 'I-04', 'name' => 'No dispatch before signed receipt', 'evidence' => 'signed_decision_receipt', 'hard_law' => true],
        ['id' => 'I-05', 'name' => 'No process start before final authorization', 'evidence' => 'final_process_start_authorization', 'hard_law' => true],
        ['id' => 'I-06', 'name' => 'No self-programming in current stage', 'evidence' => 'self_programming_disabled', 'hard_law' => true],
        ['id' => 'I-07', 'name' => 'No silent completion claim', 'evidence' => 'aligned_completion_evidence', 'hard_law' => true],
        ['id' => 'I-08', 'name' => 'No cross-axis edits in one slice', 'evidence' => 'single_axis_scope_lock', 'hard_law' => true],
        ['id' => 'I-09', 'name' => 'No untracked cleanup', 'evidence' => 'cleanup_decision_receipt', 'hard_law' => true],
        ['id' => 'I-10', 'name' => 'No worktree revert to bypass scope-lock', 'evidence' => 'rollback_receipt', 'hard_law' => true],
        ['id' => 'I-11', 'name' => 'Evidence required', 'evidence' => 'evidence_ledger_entry', 'hard_law' => true],
        ['id' => 'I-12', 'name' => 'Continuation summary required', 'evidence' => 'continuation_summary', 'hard_law' => true],
        ['id' => 'I-13', 'name' => 'Kill switch required', 'evidence' => 'kill_switch', 'hard_law' => true],
        ['id' => 'I-14', 'name' => 'Rollback required', 'evidence' => 'rollback_path', 'hard_law' => true],
        ['id' => 'I-15', 'name' => 'Scope lock required', 'evidence' => 'scope_lock_plan', 'hard_law' => true],
        ['id' => 'I-16', 'name' => 'Claim/lease required', 'evidence' => 'claim_lease', 'hard_law' => true],
    ];

    /**
     * PART 2 · A8 — the SERVING invariants (R1/R2), proposed NEW and MEASURED by the task-serving sentinel
     * ({@see \App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel}). DISTINCT from the 16 dry-run
     * hard-laws above (which stay exactly 16, doc-bound) — these govern the LIVE serving contract. Evidence is
     * the sentinel posture, never a self-report. They are DETECTORS, not mechanical guarantees (R1 is a
     * model-bound cap; the sentinel surfaces the dry-queue state honestly rather than pretending it cannot dry).
     */
    public const SERVING_INVARIANTS = [
        ['id' => 'I-17', 'name' => 'R1: the queue never dries below the floor', 'evidence' => 'queue_fill_sentinel', 'measured' => true],
        ['id' => 'I-18', 'name' => 'R2: serving never fails to deliver (empty is honest, error is a breach)', 'evidence' => 'serving_sla_sentinel', 'measured' => true],
    ];

    /**
     * I-06 is non-negotiable: if a slice declares ANY of these write surfaces
     * over Atlas code, the invariant is violated regardless of the
     * `self_programming_disabled` evidence value. (Doc I-06 violations list.)
     */
    private const SELF_PROGRAMMING_WRITE_SURFACES = [
        'writes_atlas_code',
        'learning_packet_mutates_atlas_code',
        'orchestrator_composes_atlas_code_edits',
    ];

    /**
     * Evaluate a slice against all 16 invariants and decide ship / posture /
     * promotion.
     *
     * @param  array<string, mixed>  $slice  Declared evidence map. Keys are the
     *                                        evidence keys in INVARIANTS; a value
     *                                        is "present" when truthy and (for
     *                                        strings/arrays) non-empty.
     * @return array{
     *   schema_version:string, mode:string, slice_id:string,
     *   invariant_count:int, evaluated:array<int,array<string,mixed>>,
     *   violated_ids:array<int,string>, violation_count:int,
     *   posture:string, posture_downgraded:bool, promotion_blocked:bool,
     *   ships:bool, repair_slice_required:bool, runtime_authorized:bool,
     *   relax_attempt_rejected:bool, reasons:array<int,string>
     * }
     */
    public function evaluateSlice(array $slice, array $options = []): array
    {
        $sliceId = (string) ($slice['slice_id'] ?? 'slice-unknown');

        // Doc: "An invariant cannot be 'temporarily relaxed'." Any such attempt
        // is recorded and rejected — it never converts a violation to a pass.
        $relaxAttempt = (bool) ($slice['relax_invariant']
            ?? $slice['temporary_waiver']
            ?? $options['relax_invariant']
            ?? false);

        $evaluated = [];
        $violatedIds = [];
        $reasons = [];

        foreach (self::INVARIANTS as $inv) {
            [$satisfied, $reason] = $this->evaluateInvariant($inv, $slice);

            if (! $satisfied) {
                $violatedIds[] = $inv['id'];
                $reasons[] = $inv['id'].': '.$reason;
            }

            $evaluated[] = [
                'id' => $inv['id'],
                'name' => $inv['name'],
                'evidence_key' => $inv['evidence'],
                'hard_law' => $inv['hard_law'],
                'satisfied' => $satisfied,
                'status' => $satisfied ? 'satisfied' : 'violated',
                'reason' => $reason,
            ];
        }

        if ($relaxAttempt) {
            $reasons[] = 'relax/temporary-waiver attempt rejected: invariants are hard law and cannot be relaxed.';
        }

        $violationCount = count($violatedIds);
        $hasViolation = $violationCount > 0;

        // Doc: any violation downgrades posture AND blocks promotion. There is
        // no fast path: the relax flag cannot rescue a violated slice.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'slice_id' => $sliceId,
            'invariant_count' => count(self::INVARIANTS),
            'evaluated' => $evaluated,
            'violated_ids' => $violatedIds,
            'violation_count' => $violationCount,
            'posture' => $hasViolation ? self::POSTURE_DOWNGRADED : self::POSTURE_GREEN,
            'posture_downgraded' => $hasViolation,
            'promotion_blocked' => $hasViolation,
            'ships' => ! $hasViolation,
            'repair_slice_required' => $hasViolation,
            'runtime_authorized' => false,
            'relax_attempt_rejected' => $relaxAttempt,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array{id:string,name:string,evidence:string,hard_law:bool}  $inv
     * @param  array<string, mixed>  $slice
     * @return array{0:bool,1:string}
     */
    private function evaluateInvariant(array $inv, array $slice): array
    {
        // I-06 is non-negotiable: a present write surface over Atlas code is a
        // hard violation regardless of the declared evidence.
        if ($inv['id'] === 'I-06') {
            foreach (self::SELF_PROGRAMMING_WRITE_SURFACES as $surface) {
                if (! empty($slice[$surface])) {
                    return [false, "self-programming write surface '{$surface}' present; self-programming is explicitly disabled (non-negotiable)."];
                }
            }
        }

        if ($this->present($slice, $inv['evidence'])) {
            return [true, "evidence '{$inv['evidence']}' present and ledger-reachable."];
        }

        // Doc: "If an invariant cannot be satisfied by evidence, treat it as
        // violated. 'Looks fine' is not evidence."
        return [false, "no ledger-reachable evidence under '{$inv['evidence']}'; treated as violated."];
    }

    /**
     * An evidence key is "present" only when truthy and, for strings/arrays,
     * non-empty. A bare "true" / non-empty id / non-empty list all count;
     * null / false / '' / [] / '0' do not.
     *
     * @param  array<string, mixed>  $slice
     */
    private function present(array $slice, string $key): bool
    {
        if (! array_key_exists($key, $slice)) {
            return false;
        }

        $value = $slice[$key];

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '' && trim($value) !== '0';
        }

        return (bool) $value;
    }

    /**
     * Self-describing snapshot for the CLI: the full invariant catalogue plus a
     * worked all-green evaluation proving the stop-condition contract is live.
     *
     * @return array<string, mixed>
     */
    /**
     * The proposed SERVING invariants (I-17/I-18). Returned SEPARATELY from the 16 dry-run hard-laws so the
     * doc-bound count stays exactly 16 while the live-serving invariants are declared + discoverable.
     *
     * @return list<array{id:string, name:string, evidence:string, measured:bool}>
     */
    public function servingInvariants(): array
    {
        return self::SERVING_INVARIANTS;
    }

    public function describe(): array
    {
        $green = $this->fullySatisfiedSlice('slice-worked-green');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'invariant_count' => count(self::INVARIANTS),
            'invariants' => array_map(
                static fn (array $i): array => ['id' => $i['id'], 'name' => $i['name'], 'evidence_key' => $i['evidence']],
                self::INVARIANTS,
            ),
            'rules' => [
                'each invariant is a stop condition, not a guideline',
                'missing or empty evidence is treated as violated',
                'any violation downgrades posture and blocks promotion',
                'no combination of features may relax an invariant',
                'self-programming (I-06) is explicitly disabled and non-negotiable',
            ],
            'sample_all_green' => $this->evaluateSlice($green),
            'runtime_authorized' => false,
        ];
    }

    /**
     * A slice carrying every required evidence key (all 16 satisfied), used by
     * describe() and as a test fixture seed.
     *
     * @return array<string, mixed>
     */
    public function fullySatisfiedSlice(string $sliceId): array
    {
        $slice = ['slice_id' => $sliceId];

        foreach (self::INVARIANTS as $inv) {
            $slice[$inv['evidence']] = 'ledger://'.$inv['evidence'].'/proof';
        }

        return $slice;
    }

    /** @return array<int, array{id:string,name:string,evidence:string,hard_law:bool}> */
    public function invariants(): array
    {
        return self::INVARIANTS;
    }
}

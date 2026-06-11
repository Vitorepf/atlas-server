<?php

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Runtime for the Atlas Self-Construction OS Risk Register v1 doc.
 *
 * The doc is the canonical register of the 15 named risks the Self-Construction
 * OS faces (SC-OS-R-001 .. SC-OS-R-015). Each risk declares a stable risk_id, a
 * frozen severity / likelihood, an operator-observable signal, a mitigation, an
 * owner handoff and a current status. The status legend is a closed enum:
 * open / mitigated_by_design / mitigated_by_runtime / accepted / monitoring.
 *
 * Three load-bearing rules from the doc are enforced here, deterministically, in
 * pure memory with no DB:
 *
 *   1. Runtime-mitigation gate ("Regras para IA" + forbidden_changes): a risk
 *      may be promoted to status `mitigated_by_runtime` ONLY when ALL THREE are
 *      cited together — a green promotion gate, a replay diff in {improved,
 *      passed}, AND a signed receipt id. The doc is explicit: "Nunca declarar um
 *      risco mitigated_by_runtime sem citar o gate verde + replay diff + receipt
 *      assinado." Miss any one and the promotion is REFUSED; the status holds at
 *      its declared value. Never trust a single signal (SC-OS-R-001).
 *
 *   2. Signal invariant (decisions): every risk MUST declare a signal an operator
 *      can observe BEFORE damage. All 15 risks carry a non-empty signal; a risk
 *      with no signal can never be evaluated for promotion.
 *
 *   3. Cross-cutting canonical alarm: every risk shares the same ultimate signal —
 *      at least one `runtime_safety` flag flips to `true` WITHOUT the matching
 *      signed-release evidence chain. That single observable is the canonical
 *      alarm. A runtime flag that is true without a signed release fires the
 *      alarm regardless of which risk it maps to.
 *
 * Promotion-readiness ("Fluxo"): before a slice is promoted, the register
 * requires mitigation + signal + ownership; any risk still `open` (no mitigation
 * runtime) BLOCKS promotion (failure mode: "Promover slice com risco ativo e sem
 * mitigacao").
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
 */
final class AtlasSelfConstructionOsRiskRegisterService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.self_construction_os_risk_register.v1';

    public const MODE = 'read_only_risk_register';

    /** Canonical position date recorded in the Resumo. */
    public const POSITION_DATE = '2026-05-14';

    /** Status legend (closed enum) declared at the top of the doc. */
    public const STATUS_OPEN = 'open';

    public const STATUS_MITIGATED_BY_DESIGN = 'mitigated_by_design';

    public const STATUS_MITIGATED_BY_RUNTIME = 'mitigated_by_runtime';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_MONITORING = 'monitoring';

    /**
     * The valid status legend. Any status outside this set is invalid.
     *
     * @var array<int, string>
     */
    public const STATUS_LEGEND = [
        self::STATUS_OPEN,
        self::STATUS_MITIGATED_BY_DESIGN,
        self::STATUS_MITIGATED_BY_RUNTIME,
        self::STATUS_ACCEPTED,
        self::STATUS_MONITORING,
    ];

    /** Severity legend declared at the top of the doc. */
    public const SEVERITY_LEGEND = ['critical', 'high', 'medium', 'low'];

    /** Likelihood legend declared at the top of the doc. */
    public const LIKELIHOOD_LEGEND = ['high', 'medium', 'low'];

    /** Replay diff statuses the Regras para IA accept as non-blocking. */
    public const ACCEPTABLE_REPLAY_DIFF_STATUSES = ['improved', 'passed'];

    /** Promotion gate statuses the Regras para IA accept as green. */
    public const ACCEPTABLE_PROMOTION_GATE_STATUSES = ['green', 'passed'];

    /**
     * The 15 risks, frozen exactly as the doc declares them. `severity`,
     * `likelihood` and `status` are the doc's stated values; `signal` is the
     * operator-observable signal; `runtime_flag` is the specific runtime_safety
     * flag whose flip-to-true is the per-risk alarm (empty when the risk maps to
     * the shared evidence/ledger alarm rather than a single named flag).
     *
     * @var array<string, array{title:string, severity:string, likelihood:string, status:string, signal:string, runtime_flag:string}>
     */
    public const RISKS = [
        'SC-OS-R-001' => [
            'title' => 'False Green Certification',
            'severity' => 'high',
            'likelihood' => 'medium',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'replay_diff_status_flips_unchanged_to_regressed_or_coverage_grade_drops_or_detection_rate_below_1',
            'runtime_flag' => '',
        ],
        'SC-OS-R-002' => [
            'title' => 'Pointer Regression',
            'severity' => 'high',
            'likelihood' => 'low',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'current_pointer_not_equal_expected_pointer_or_pointer_regression_scenario_fires',
            'runtime_flag' => '',
        ],
        'SC-OS-R-003' => [
            'title' => 'Accidental Provider Call',
            'severity' => 'critical',
            'likelihood' => 'medium',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'runtime_safety_flag_flips_true_without_signed_release_or_ledger_event_count_above_zero_without_signed_dispatch',
            'runtime_flag' => 'execution_allowed',
        ],
        'SC-OS-R-004' => [
            'title' => 'Token Spend Without Budget',
            'severity' => 'critical',
            'likelihood' => 'medium',
            'status' => self::STATUS_OPEN,
            'signal' => 'cost_event_count_rises_without_budget_envelope_or_provider_response_stored_without_cost_record',
            'runtime_flag' => '',
        ],
        'SC-OS-R-005' => [
            'title' => 'Adapter Execution Before Guard',
            'severity' => 'critical',
            'likelihood' => 'low',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'adapter_invocation_metadata_present_without_guard_evidence_or_adapter_execution_allowed_true',
            'runtime_flag' => 'adapter_execution_allowed',
        ],
        'SC-OS-R-006' => [
            'title' => 'Dispatch Before Signed Receipt',
            'severity' => 'critical',
            'likelihood' => 'low',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'dispatch_allowed_true_without_signed_real_invoker_release_evidence',
            'runtime_flag' => 'dispatch_allowed',
        ],
        'SC-OS-R-007' => [
            'title' => 'Process Start Before Final Authorization',
            'severity' => 'critical',
            'likelihood' => 'low',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'process_started_or_external_process_started_or_atlas_process_spawned_true_without_final_authorization',
            'runtime_flag' => 'process_started',
        ],
        'SC-OS-R-008' => [
            'title' => 'Multi-Agent Write Conflict',
            'severity' => 'high',
            'likelihood' => 'high',
            'status' => self::STATUS_OPEN,
            'signal' => 'same_path_in_two_parallel_claims_or_simultaneous_edits_in_different_corridors_or_merge_conflicts_on_rebase',
            'runtime_flag' => '',
        ],
        'SC-OS-R-009' => [
            'title' => 'Stale Lease',
            'severity' => 'medium',
            'likelihood' => 'high',
            'status' => self::STATUS_OPEN,
            'signal' => 'claim_age_above_ttl_or_heartbeat_absent_or_wakeup_item_stuck_in_claimed',
            'runtime_flag' => '',
        ],
        'SC-OS-R-010' => [
            'title' => 'Missing Continuation Summary',
            'severity' => 'medium',
            'likelihood' => 'medium',
            'status' => self::STATUS_OPEN,
            'signal' => 'session_bootstrap_returns_empty_summary_while_prior_reservation_in_progress',
            'runtime_flag' => '',
        ],
        'SC-OS-R-011' => [
            'title' => 'Evidence Ledger Drift',
            'severity' => 'high',
            'likelihood' => 'medium',
            'status' => self::STATUS_OPEN,
            'signal' => 'ledger_event_count_vs_claim_count_vs_receipt_count_diverge_or_replay_diff_mismatch_in_evidence_index',
            'runtime_flag' => 'ledger_write_allowed',
        ],
        'SC-OS-R-012' => [
            'title' => 'Work Product Loss',
            'severity' => 'medium',
            'likelihood' => 'high',
            'status' => self::STATUS_OPEN,
            'signal' => 'completed_runs_above_zero_but_persistent_work_products_zero_or_manifest_missing_workspace_artifacts',
            'runtime_flag' => '',
        ],
        'SC-OS-R-013' => [
            'title' => 'Cost Import Mismatch',
            'severity' => 'high',
            'likelihood' => 'medium',
            'status' => self::STATUS_OPEN,
            'signal' => 'cost_event_amount_diverges_from_provider_invoice_or_double_counted_events_or_cost_event_without_dispatch_receipt',
            'runtime_flag' => '',
        ],
        'SC-OS-R-014' => [
            'title' => 'Cross-Claude File Conflict',
            'severity' => 'high',
            'likelihood' => 'high',
            // Doc: "mitigated_by_procedure (this audit run); open at the runtime level."
            'status' => self::STATUS_OPEN,
            'signal' => 'git_status_shows_simultaneous_modifications_by_two_corridors_or_rebase_conflict_on_merge',
            'runtime_flag' => '',
        ],
        'SC-OS-R-015' => [
            'title' => 'Self-Programming Premature Activation',
            'severity' => 'critical',
            'likelihood' => 'low',
            'status' => self::STATUS_MITIGATED_BY_DESIGN,
            'signal' => 'self_programming_allowed_true_without_required_preconditions_or_receipt_scope_omits_rollback_strategy',
            'runtime_flag' => 'self_programming_allowed',
        ],
    ];

    /**
     * Evaluate a single risk's identity card. PURE — no I/O, no DB. Returns the
     * frozen severity / likelihood / status / signal plus whether the declared
     * status is a legal legend value and whether a signal is present (the
     * decisions invariant requires every risk to declare one).
     *
     * @return array{
     *   id:string,
     *   recognized:bool,
     *   title:string,
     *   severity:string,
     *   likelihood:string,
     *   status:string,
     *   status_valid:bool,
     *   signal:string,
     *   has_signal:bool,
     *   runtime_flag:string,
     *   is_open:bool
     * }
     */
    public function evaluateRisk(string $id): array
    {
        $normalizedId = $this->normalizeId($id);
        $meta = self::RISKS[$normalizedId] ?? null;

        if ($meta === null) {
            return [
                'id' => $id,
                'recognized' => false,
                'title' => '',
                'severity' => '',
                'likelihood' => '',
                'status' => '',
                'status_valid' => false,
                'signal' => '',
                'has_signal' => false,
                'runtime_flag' => '',
                'is_open' => true,
            ];
        }

        $signal = trim($meta['signal']);

        return [
            'id' => $normalizedId,
            'recognized' => true,
            'title' => $meta['title'],
            'severity' => $meta['severity'],
            'likelihood' => $meta['likelihood'],
            'status' => $meta['status'],
            'status_valid' => in_array($meta['status'], self::STATUS_LEGEND, true),
            'signal' => $signal,
            'has_signal' => $signal !== '',
            'runtime_flag' => $meta['runtime_flag'],
            'is_open' => $meta['status'] === self::STATUS_OPEN,
        ];
    }

    /**
     * Decide whether a risk may be promoted to `mitigated_by_runtime`. Per
     * "Regras para IA" + forbidden_changes, this is allowed ONLY when a green
     * promotion gate, an acceptable replay diff status, AND a signed receipt id
     * are ALL cited. Miss any one and the promotion is refused; the status holds
     * at its currently declared value. PURE.
     *
     * @param  array<string, mixed>  $evidence {
     *   promotion_gate_status?:string, replay_diff_status?:string, signed_receipt?:string
     * }
     * @return array{
     *   id:string,
     *   recognized:bool,
     *   current_status:string,
     *   promoted:bool,
     *   resulting_status:string,
     *   missing_evidence:array<int,string>,
     *   invalid_evidence:array<int,string>,
     *   promotion_gate_status:string,
     *   replay_diff_status:string,
     *   reason:string
     * }
     */
    public function evaluateRuntimeMitigation(string $id, array $evidence = []): array
    {
        $card = $this->evaluateRisk($id);

        if (! $card['recognized']) {
            return [
                'id' => $id,
                'recognized' => false,
                'current_status' => '',
                'promoted' => false,
                'resulting_status' => '',
                'missing_evidence' => ['signed_receipt', 'replay_diff_status', 'promotion_gate_status'],
                'invalid_evidence' => [],
                'promotion_gate_status' => '',
                'replay_diff_status' => '',
                'reason' => 'unknown_risk_not_in_register',
            ];
        }

        $missing = [];
        $invalid = [];

        // a. signed receipt id.
        $receipt = $evidence['signed_receipt'] ?? null;
        if ($this->isEmpty($receipt)) {
            $missing[] = 'signed_receipt';
        }

        // b. replay diff status — must be improved or passed.
        $replay = $evidence['replay_diff_status'] ?? null;
        if ($this->isEmpty($replay)) {
            $missing[] = 'replay_diff_status';
        } elseif (! in_array((string) $replay, self::ACCEPTABLE_REPLAY_DIFF_STATUSES, true)) {
            $invalid[] = 'replay_diff_status';
        }

        // c. promotion gate status — must be green/passed.
        $gate = $evidence['promotion_gate_status'] ?? null;
        if ($this->isEmpty($gate)) {
            $missing[] = 'promotion_gate_status';
        } elseif (! in_array((string) $gate, self::ACCEPTABLE_PROMOTION_GATE_STATUSES, true)) {
            $invalid[] = 'promotion_gate_status';
        }

        $allThreePresent = $missing === [] && $invalid === [];
        $promoted = $allThreePresent;

        $reason = match (true) {
            $promoted => 'promoted_to_mitigated_by_runtime_gate_replay_and_receipt_all_present',
            $invalid !== [] => 'evidence_present_but_invalid_status_value',
            default => 'missing_required_runtime_mitigation_evidence',
        };

        return [
            'id' => $card['id'],
            'recognized' => true,
            'current_status' => $card['status'],
            'promoted' => $promoted,
            // Only a fully-proven promotion flips the status; otherwise it holds.
            'resulting_status' => $promoted ? self::STATUS_MITIGATED_BY_RUNTIME : $card['status'],
            'missing_evidence' => $missing,
            'invalid_evidence' => $invalid,
            'promotion_gate_status' => (string) ($evidence['promotion_gate_status'] ?? ''),
            'replay_diff_status' => (string) ($evidence['replay_diff_status'] ?? ''),
            'reason' => $reason,
        ];
    }

    /**
     * Cross-cutting canonical alarm. Every risk shares the same ultimate signal:
     * at least one `runtime_safety` flag is `true` WITHOUT the matching signed
     * release. Given a sampled runtime_safety block and the set of signed-release
     * ids present, report which flags fired the alarm. A flag that is true is a
     * violation UNLESS a signed release is cited. PURE.
     *
     * @param  array<string, mixed>  $runtimeSafety  flag => bool
     * @param  array<int, string>  $signedReleases  signed release ids that authorize a true flag
     * @return array{
     *   alarm_firing:bool,
     *   runtime_safety_all_false:bool,
     *   has_signed_release:bool,
     *   firing_flags:array<int, string>,
     *   true_flags:array<int, string>
     * }
     */
    public function evaluateCanonicalAlarm(array $runtimeSafety, array $signedReleases = []): array
    {
        $trueFlags = [];
        foreach ($runtimeSafety as $flag => $value) {
            if ($value === true) {
                $trueFlags[] = (string) $flag;
            }
        }

        $hasSignedRelease = AtlasAaeosStringListNormalizer::trimmedStrings($signedReleases) !== [];

        // A true flag is a violation only when no signed release authorizes it.
        $firingFlags = $hasSignedRelease ? [] : $trueFlags;

        return [
            'alarm_firing' => $firingFlags !== [],
            'runtime_safety_all_false' => $trueFlags === [],
            'has_signed_release' => $hasSignedRelease,
            'firing_flags' => $firingFlags,
            'true_flags' => $trueFlags,
        ];
    }

    /**
     * Promotion-readiness gate ("Fluxo" + failure_modes). Before a slice is
     * promoted, every risk it touches must carry mitigation + signal + ownership.
     * A risk still `open` (no mitigation runtime) BLOCKS the promotion. Risks may
     * be promoted to `mitigated_by_runtime` first by passing runtime-mitigation
     * evidence; only the remaining-open risks block. PURE.
     *
     * @param  array<int, string>  $riskIds  risks the slice touches
     * @param  array<string, array<string,mixed>>  $runtimeMitigations  risk id => evidence
     * @return array{
     *   promotion_blocked:bool,
     *   blocking_risks:array<int, string>,
     *   cleared_risks:array<int, string>,
     *   reason:string
     * }
     */
    public function evaluateSlicePromotion(array $riskIds, array $runtimeMitigations = []): array
    {
        $blocking = [];
        $cleared = [];

        foreach ($riskIds as $rid) {
            $card = $this->evaluateRisk($rid);

            if (! $card['recognized']) {
                $blocking[] = $this->normalizeId($rid);

                continue;
            }

            // A risk that is not open is already mitigated (by design/runtime),
            // accepted, or monitored -> it does not block.
            if (! $card['is_open']) {
                $cleared[] = $card['id'];

                continue;
            }

            // Open risk: it can still clear IF the operator supplied full
            // runtime-mitigation evidence that promotes it this run.
            $mitigation = $this->evaluateRuntimeMitigation($card['id'], $runtimeMitigations[$card['id']] ?? []);
            if ($mitigation['promoted']) {
                $cleared[] = $card['id'];
            } else {
                $blocking[] = $card['id'];
            }
        }

        $blocked = $blocking !== [];

        return [
            'promotion_blocked' => $blocked,
            'blocking_risks' => $blocking,
            'cleared_risks' => $cleared,
            'reason' => $blocked
                ? 'open_risk_without_mitigation_runtime_blocks_slice_promotion'
                : 'all_touched_risks_mitigated_slice_may_promote',
        ];
    }

    /**
     * Full read-only register snapshot. By default it renders all 15 risks with
     * their frozen status; the open risks are the ones the doc still flags as
     * unmitigated runtime. A caller MAY pass a map of risk id => runtime-mitigation
     * evidence; only risks whose evidence passes the three-part gate are reported
     * as promotable to `mitigated_by_runtime`. Everything else holds its status.
     *
     * @param  array<string, array<string,mixed>>  $runtimeMitigations
     * @return array<string, mixed>
     */
    public function snapshot(array $runtimeMitigations = []): array
    {
        $risks = [];
        $openIds = [];
        $promotableIds = [];
        $bySeverity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach (array_keys(self::RISKS) as $rid) {
            $card = $this->evaluateRisk($rid);
            $risks[] = $card;

            if (isset($bySeverity[$card['severity']])) {
                $bySeverity[$card['severity']]++;
            }

            if ($card['is_open']) {
                $openIds[] = $rid;

                $mitigation = $this->evaluateRuntimeMitigation($rid, $runtimeMitigations[$rid] ?? []);
                if ($mitigation['promoted']) {
                    $promotableIds[] = $rid;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'position_date' => self::POSITION_DATE,
            'status_legend' => self::STATUS_LEGEND,
            'risk_count' => count(self::RISKS),
            'open_count' => count($openIds),
            'open_risks' => $openIds,
            'severity_breakdown' => $bySeverity,
            // Decisions invariant: every risk declares an observable signal.
            'all_risks_have_signal' => $this->allRisksHaveSignal(),
            'runtime_promotable_open_risks' => $promotableIds,
            'risks' => $risks,
            'runtime_mitigation_rule' => 'A risk is promoted to mitigated_by_runtime ONLY with a green promotion gate AND a replay diff in {improved,passed} AND a signed receipt — never a single signal.',
            'canonical_alarm' => 'Every risk shares one ultimate signal: any runtime_safety flag flips to true without the matching signed-release evidence chain.',
        ];
    }

    /**
     * Decisions invariant check: every risk in the register declares a non-empty
     * signal an operator can observe before damage.
     */
    public function allRisksHaveSignal(): bool
    {
        foreach (self::RISKS as $meta) {
            if (trim($meta['signal']) === '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeId(string $id): string
    {
        $upper = strtoupper(trim($id));
        // Accept "sc-os-r-1", "SCOSR3", "003", "3" -> "SC-OS-R-003".
        if (preg_match('/^SC-?OS-?R-?(\d{1,3})$/', $upper, $m) === 1) {
            return 'SC-OS-R-'.str_pad((string) ((int) $m[1]), 3, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{1,3})$/', $upper, $m) === 1) {
            return 'SC-OS-R-'.str_pad((string) ((int) $m[1]), 3, '0', STR_PAD_LEFT);
        }

        return $upper;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }

}

<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Atlas Self-Construction OS Gap Audit v1 doc.
 *
 * The doc is a READ-ONLY audit that separates three buckets — what already
 * EXISTS (observed), what is still CONTRACT / CERTIFICATION / DRY-RUN, and what
 * is NOT YET RUNTIME — and then (Section 8) lists 15 specific claims that MUST
 * NOT be made "without producing a signed promotion artifact AND a green replay
 * diff AND a green promotion gate".
 *
 * Two load-bearing rules from the doc are enforced here, deterministically, in
 * pure memory with no DB:
 *
 *   1. Forbidden-claims gate (Section 8 + "Regras para IA"): every one of the 15
 *      named claims STAYS forbidden unless ALL THREE conditions are cited:
 *        a. a non-empty signed promotion artifact id;
 *        b. a replay diff status that is exactly "improved" or "passed"
 *           (never "regressed");
 *        c. a promotion gate status that is exactly "green"/"passed".
 *      Miss any one -> the claim is refused. The audit itself never promotes.
 *
 *   2. Observed evidence invariants (Section 7): the audited counters are frozen
 *      — 482 capabilities, 4 not_yet_runtime_capable, exactly 1 next_build_slice,
 *      34 chain-integrity slices with 0 violations / 0 warnings, and ALL runtime
 *      flags false (execution / dispatch / ledger-write / self-programming).
 *      A reader can verify a sampled projection against these without re-running
 *      the read-only commands, and can never silently flip a runtime flag true.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-gap-audit-v1.md
 */
final class AtlasSelfConstructionOsGapAuditService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.self_construction_os_gap_audit.v1';

    public const MODE = 'observational_read_only_audit';

    public const AUDIT_WINDOW = '2026-05-14';

    /** Bucket labels the doc uses (Sections 2, 3, 4). */
    public const BUCKET_OBSERVED = 'observed';

    public const BUCKET_CONTRACT_CERT_DRYRUN = 'contract_certification_dry_run';

    public const BUCKET_NOT_YET_RUNTIME = 'not_yet_runtime';

    public const BUCKET_UNKNOWN = 'unknown';

    /**
     * The 15 claims from Section 8 ("Claims that MUST NOT Be Made Yet"). Each is
     * forbidden unless the three-part promotion proof is cited in full.
     *
     * @var array<int, string>
     */
    public const FORBIDDEN_CLAIMS = [
        'atlas_self_construction_os_is_complete',
        'agent_control_plane_is_runtime_ready',
        'atlas_can_dispatch_providers_autonomously',
        'self_programming_is_enabled',
        'atlas_can_start_its_own_provider_process',
        'atlas_can_mark_dispatch_receipts_used_automatically',
        'atlas_writes_to_the_evidence_ledger_automatically',
        'multi_agent_parallel_execution_is_live',
        'forge_activation_is_wired_into_agent_control_plane_runtime',
        'cost_import_is_automatic',
        'work_product_collection_is_automatic',
        'kill_switch_is_wired',
        'rollback_is_wired',
        'budget_guard_is_wired',
        'atlas_can_resume_sessions_automatically_across_providers',
    ];

    /** Replay diff statuses Section 8 accepts as non-blocking. */
    public const ACCEPTABLE_REPLAY_DIFF_STATUSES = ['improved', 'passed'];

    /** Promotion gate statuses Section 8 accepts as green. */
    public const ACCEPTABLE_PROMOTION_GATE_STATUSES = ['green', 'passed'];

    /**
     * Section 4 confirmed `not_yet_runtime_capable` list (4 entries).
     *
     * @var array<int, string>
     */
    public const NOT_YET_RUNTIME_CAPABLE = [
        'adapter_execution_runtime',
        'automatic_cost_import_runtime',
        'automatic_work_product_collection_runtime',
        'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
    ];

    /** Section 4 `next_build_slices` — exactly one target. */
    public const NEXT_BUILD_SLICE = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';

    /**
     * Section 7 observed evidence summary — the frozen audit-window invariants.
     * Any sampled projection that diverges from these is drift, not the audit.
     *
     * @var array<string, int|bool>
     */
    public const OBSERVED_EVIDENCE = [
        'agent_control_plane_capability_count' => 482,
        'not_yet_runtime_capable_count' => 4,
        'next_build_slice_count' => 1,
        'chain_integrity_slice_count' => 34,
        'chain_integrity_violation_count' => 0,
        'chain_integrity_warning_count' => 0,
        'ledger_event_count' => 0,
        'execution_allowed' => false,
        'dispatch_allowed' => false,
        'ledger_write_allowed' => false,
        'self_programming_allowed' => false,
        'invariants_all_true' => true,
        'runtime_safety_all_false' => true,
    ];

    /**
     * Section 3 surfaces that DO emit hashable evidence but DO NOT execute.
     * A reader must not mistake any of these for runtime.
     *
     * @var array<int, string>
     */
    public const CONTRACT_CERT_DRYRUN_SURFACES = [
        'task_packet_builder',
        'claim_lease_simulator',
        'scope_lock_planner',
        'evidence_ledger_dry_run',
        'continuation_summary_builder',
        'work_product_manifest_planner',
        'cost_import_dry_run',
        'multi_agent_parallelism_planner',
        'automatic_dispatch_scheduler_chain',
    ];

    /**
     * Decide whether a named claim from Section 8 may be made. The claim is
     * refused unless the signed promotion artifact, an acceptable replay diff
     * status, and a green promotion gate are ALL cited. PURE — no I/O, no DB.
     *
     * @param  array<string, mixed>  $promotion
     * @return array{
     *   claim:string,
     *   recognized_forbidden_claim:bool,
     *   allowed:bool,
     *   missing_proofs:array<int,string>,
     *   invalid_proofs:array<int,string>,
     *   replay_diff_status:string,
     *   promotion_gate_status:string,
     *   reason:string
     * }
     */
    public function evaluateClaim(string $claim, array $promotion = []): array
    {
        $slug = $this->slugify($claim);
        $recognized = in_array($slug, self::FORBIDDEN_CLAIMS, true);

        $missing = [];
        $invalid = [];

        // a. signed promotion artifact id.
        $artifact = $promotion['signed_promotion_artifact'] ?? null;
        if ($this->isEmpty($artifact)) {
            $missing[] = 'signed_promotion_artifact';
        }

        // b. replay diff status — must be improved or passed.
        $replay = $promotion['replay_diff_status'] ?? null;
        if ($this->isEmpty($replay)) {
            $missing[] = 'replay_diff_status';
        } elseif (! in_array((string) $replay, self::ACCEPTABLE_REPLAY_DIFF_STATUSES, true)) {
            $invalid[] = 'replay_diff_status';
        }

        // c. promotion gate status — must be green/passed.
        $gate = $promotion['promotion_gate_status'] ?? null;
        if ($this->isEmpty($gate)) {
            $missing[] = 'promotion_gate_status';
        } elseif (! in_array((string) $gate, self::ACCEPTABLE_PROMOTION_GATE_STATUSES, true)) {
            $invalid[] = 'promotion_gate_status';
        }

        $allThreePresent = $missing === [] && $invalid === [];
        // A claim is only releasable if it is one of the recognized forbidden
        // claims AND the full three-part proof passes. Unrecognized strings are
        // not auto-allowed — the audit can only clear claims it governs.
        $allowed = $recognized && $allThreePresent;

        $reason = match (true) {
            $allowed => 'claim_released_signed_promotion_replay_and_gate_all_present',
            ! $recognized => 'claim_not_in_forbidden_set_audit_cannot_release_unknown_claim',
            $invalid !== [] => 'proof_present_but_invalid',
            default => 'missing_required_promotion_proofs',
        };

        return [
            'claim' => $claim,
            'recognized_forbidden_claim' => $recognized,
            'allowed' => $allowed,
            'missing_proofs' => $missing,
            'invalid_proofs' => $invalid,
            'replay_diff_status' => (string) ($promotion['replay_diff_status'] ?? ''),
            'promotion_gate_status' => (string) ($promotion['promotion_gate_status'] ?? ''),
            'reason' => $reason,
        ];
    }

    /**
     * Classify a surface name into one of the doc's three buckets. Used so a
     * reader never renders a contract/dry-run surface as runtime. PURE.
     */
    public function classifySurface(string $surface): string
    {
        $slug = $this->slugify($surface);

        if (in_array($slug, self::NOT_YET_RUNTIME_CAPABLE, true)) {
            return self::BUCKET_NOT_YET_RUNTIME;
        }
        if (in_array($slug, self::CONTRACT_CERT_DRYRUN_SURFACES, true)) {
            return self::BUCKET_CONTRACT_CERT_DRYRUN;
        }

        return self::BUCKET_UNKNOWN;
    }

    /**
     * Verify a sampled read-only projection against the Section 7 frozen
     * invariants. Any divergence is reported as drift; any runtime flag that is
     * not false is reported as a safety violation. PURE.
     *
     * @param  array<string, mixed>  $sampled
     * @return array{
     *   matches_audit:bool,
     *   runtime_safety_intact:bool,
     *   drift:array<int, array{key:string, expected:int|bool, actual:mixed}>,
     *   runtime_safety_violations:array<int, string>
     * }
     */
    public function verifyObservedEvidence(array $sampled): array
    {
        $drift = [];
        $safetyViolations = [];
        $runtimeFlags = [
            'execution_allowed',
            'dispatch_allowed',
            'ledger_write_allowed',
            'self_programming_allowed',
        ];

        foreach (self::OBSERVED_EVIDENCE as $key => $expected) {
            if (! array_key_exists($key, $sampled)) {
                continue; // not sampled this run; nothing to compare
            }

            $actual = $sampled[$key];
            if ($actual !== $expected) {
                $drift[] = ['key' => $key, 'expected' => $expected, 'actual' => $actual];

                if (in_array($key, $runtimeFlags, true) && $actual !== false) {
                    $safetyViolations[] = $key;
                }
            }
        }

        return [
            'matches_audit' => $drift === [],
            'runtime_safety_intact' => $safetyViolations === [],
            'drift' => $drift,
            'runtime_safety_violations' => $safetyViolations,
        ];
    }

    /**
     * Full read-only audit snapshot. By default (no promotions cited) every one
     * of the 15 claims is forbidden, exactly as the doc states. A caller MAY pass
     * a map of claim => promotion-proof; only claims whose proof passes the
     * three-part gate are released. Everything else stays forbidden.
     *
     * @param  array<string, array<string,mixed>>  $claimPromotions
     * @return array<string, mixed>
     */
    public function snapshot(array $claimPromotions = []): array
    {
        $claims = [];
        $releasedClaims = [];

        foreach (self::FORBIDDEN_CLAIMS as $claim) {
            $result = $this->evaluateClaim($claim, $claimPromotions[$claim] ?? []);
            if ($result['allowed']) {
                $releasedClaims[] = $claim;
            }
            $claims[] = $result;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'audit_window' => self::AUDIT_WINDOW,
            'observed_evidence' => self::OBSERVED_EVIDENCE,
            'not_yet_runtime_capable' => self::NOT_YET_RUNTIME_CAPABLE,
            'next_build_slice' => self::NEXT_BUILD_SLICE,
            'contract_certification_dry_run_surfaces' => self::CONTRACT_CERT_DRYRUN_SURFACES,
            'forbidden_claim_count' => count(self::FORBIDDEN_CLAIMS),
            'released_claim_count' => count($releasedClaims),
            'released_claims' => $releasedClaims,
            // Doc invariant: with no signed promotion bundle, nothing is released.
            'all_claims_forbidden' => $releasedClaims === [],
            'claims' => $claims,
            'release_rule' => 'A Section 8 claim is releasable ONLY with a signed promotion artifact AND replay diff in {improved,passed} AND a green promotion gate. The audit never auto-releases.',
        ];
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

    private function slugify(string $value): string
    {
        $slug = strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }
}

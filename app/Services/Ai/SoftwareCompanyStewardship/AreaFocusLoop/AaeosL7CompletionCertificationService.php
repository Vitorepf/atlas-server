<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S100 — AaeosL7CompletionCertificationService (block: L7 Runtime Completion).
 *
 * Read-only certification of the L7 arrival checklist. It COMPOSES the S83-S99
 * gates into a deterministic verdict: it never promotes a level, never mutates
 * state and never hides a blocker. Where L7ProofBundleService (S97) builds the
 * operator-facing proof bundle from the roadmap's "Como saber que chegamos ao
 * L7" bullets, this service certifies the full S83-S99 gate ladder as the nine
 * convergence phases (Fase 0..8 of atlas-aaeos-l7-convergence-roadmap.md) plus
 * the mandatory promotion receipt.
 *
 * Verdict rules (ordered, honesty-first):
 *   - each of the nine S83-S99 gate phases (0..8) is met only when its evidence
 *     explicitly asserts the gate passed; an unmet gate emits its named blocker;
 *   - certified=true ONLY when every S83-S99 gate phase passes AND a valid
 *     promotion receipt exists (status `certified_l7`, current_level L7);
 *   - if every gate passes but the promotion receipt is missing, the verdict is
 *     `needs_signature_or_promotion` (current_level stays L6) — code is ready but
 *     the L6->L7 promotion still needs the operator+architect signature/receipt;
 *   - if any S83-S99 gate phase is unmet, the verdict is `blocked_not_l7`
 *     (current_level stays L6) and the blocker is surfaced, never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
 */
final class AaeosL7CompletionCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l7_completion_certification.v1';

    /** Pre-L7 conductor rung the runtime sits at until certification lands. */
    private const LEVEL_PRE_L7 = 'L6';

    /** Certified self-evolving rung. */
    private const LEVEL_L7 = 'L7';

    public const STATUS_CERTIFIED = 'certified_l7';

    public const STATUS_NEEDS_SIGNATURE_OR_PROMOTION = 'needs_signature_or_promotion';

    public const STATUS_BLOCKED = 'blocked_not_l7';

    /**
     * The nine S83-S99 gate phases (0..8), ordered, that compose L7 completion.
     *
     * Each phase binds a roadmap convergence Fase to the S83-S99 gate that
     * proves it, the `$inputs['gates']` key carrying that gate's pass evidence
     * and the blocker emitted when the gate is not met. Phase 8 is the promotion
     * receipt gate and is handled distinctly so a missing receipt (with every
     * other gate green) yields `needs_signature_or_promotion` rather than a
     * generic block.
     *
     * @var list<array{phase:int,key:string,slice:string,blocker:string,evidence_prefix:string}>
     */
    private const PHASES = [
        ['phase' => 0, 'key' => 'loop_executes_real', 'slice' => 'S83', 'blocker' => 'loop_not_real_tier_scan_only', 'evidence_prefix' => 'evidence://l7/loop_executes_real/'],
        ['phase' => 1, 'key' => 'cohort_evidence_real', 'slice' => 'S84', 'blocker' => 'cohort_evidence_incomplete', 'evidence_prefix' => 'evidence://l7/cohort_evidence_real/'],
        ['phase' => 2, 'key' => 'utilization_quality_pass', 'slice' => 'S85', 'blocker' => 'utilization_quality_below_threshold', 'evidence_prefix' => 'evidence://l7/utilization_quality_pass/'],
        ['phase' => 3, 'key' => 'departments_l4', 'slice' => 'S87', 'blocker' => 'departments_below_l4', 'evidence_prefix' => 'evidence://l7/departments_l4/'],
        ['phase' => 4, 'key' => 'http_path_mission_control', 'slice' => 'S88', 'blocker' => 'http_path_or_mission_control_missing', 'evidence_prefix' => 'evidence://l7/http_path_mission_control/'],
        ['phase' => 5, 'key' => 'self_construction_proven', 'slice' => 'S92', 'blocker' => 'self_construction_proposals_incomplete', 'evidence_prefix' => 'evidence://l7/self_construction_proven/'],
        ['phase' => 6, 'key' => 'trust_ledger_stable', 'slice' => 'S93', 'blocker' => 'trust_ledger_below_threshold', 'evidence_prefix' => 'evidence://l7/trust_ledger_stable/'],
        ['phase' => 7, 'key' => 'no_invariant_breach', 'slice' => 'S94', 'blocker' => 'invariant_breach_present', 'evidence_prefix' => 'evidence://l7/no_invariant_breach/'],
        ['phase' => 8, 'key' => 'promotion_recorded', 'slice' => 'S96', 'blocker' => 'promotion_receipt_missing', 'evidence_prefix' => 'evidence://l7/promotion_recorded/'],
    ];

    /** Phase index of the promotion-receipt gate within self::PHASES. */
    private const PROMOTION_PHASE = 8;

    /**
     * Certify the L7 checklist by composing the S83-S99 gates.
     *
     * Recognised `$inputs`:
     *   - gates: array<string,mixed> keyed by the PHASES keys; each entry may be
     *     a bool, or an array carrying `passed`/`met`/`pass` (bool) and an
     *     optional `evidence_ref`/`ref` (non-empty string).
     *   - promotion_receipt: array<string,mixed>|null — counts as present only
     *     when it carries `applied===true` (or `present===true`) and a non-empty
     *     `receipt_id`/`id`/`evidence_ref`/`ref`. Mirrors the S96 receipt shape.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     checklist:list<array{phase:int,key:string,slice:string,passed:bool,evidence_ref:string}>,
     *     phase_total:int,
     *     phases_passed_count:int,
     *     current_level:string,
     *     certified:bool,
     *     status:string,
     *     promotion_receipt_present:bool,
     *     blockers:list<string>,
     *     evidence_refs:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $gateInput = is_array($inputs['gates'] ?? null) ? $inputs['gates'] : [];
        $receiptPresent = $this->promotionReceiptPresent($inputs['promotion_receipt'] ?? null);

        $checklist = [];
        $evidenceRefs = [];
        $gateBlockers = [];
        $receiptBlocker = false;
        $passedCount = 0;

        foreach (self::PHASES as $item) {
            $key = $item['key'];

            if ($item['phase'] === self::PROMOTION_PHASE) {
                // The promotion receipt is the phase-8 gate, resolved from the
                // dedicated receipt input rather than the gate map.
                $passed = $receiptPresent;
                $evidenceRef = $this->promotionEvidenceRef($inputs['promotion_receipt'] ?? null, $item['evidence_prefix'], $passed);
            } else {
                $raw = $gateInput[$key] ?? null;
                $passed = AreaFocusEvidenceRefNormalizer::gatePassed($raw);
                $evidenceRef = AreaFocusEvidenceRefNormalizer::gateEvidenceRef($raw, $item['evidence_prefix'], $passed);
            }

            $checklist[] = [
                'phase' => $item['phase'],
                'key' => $key,
                'slice' => $item['slice'],
                'passed' => $passed,
                'evidence_ref' => $evidenceRef,
            ];

            if ($passed) {
                $passedCount++;
                $evidenceRefs[] = $evidenceRef;

                continue;
            }

            if ($item['phase'] === self::PROMOTION_PHASE) {
                $receiptBlocker = true;
            } else {
                $gateBlockers[] = $item['blocker'];
            }
        }

        // Status precedence (ordered): a real S83-S99 gate gap blocks first; only
        // when every gate is green does a missing receipt become the verdict;
        // certification requires both. Blockers list the gate gaps first, then
        // the receipt gap, so the operator reads the same ordered story.
        $gatesAllPass = $gateBlockers === [];

        if (! $gatesAllPass) {
            $status = self::STATUS_BLOCKED;
        } elseif ($receiptBlocker) {
            $status = self::STATUS_NEEDS_SIGNATURE_OR_PROMOTION;
        } else {
            $status = self::STATUS_CERTIFIED;
        }

        $certified = $status === self::STATUS_CERTIFIED;

        $blockers = $gateBlockers;
        if ($receiptBlocker) {
            $blockers[] = self::PHASES[self::PROMOTION_PHASE]['blocker'];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'checklist' => $checklist,
            'phase_total' => count(self::PHASES),
            'phases_passed_count' => $passedCount,
            'current_level' => $certified ? self::LEVEL_L7 : self::LEVEL_PRE_L7,
            'certified' => $certified,
            'status' => $status,
            'promotion_receipt_present' => $receiptPresent,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * The promotion receipt is present only when it is applied (or explicitly
     * marked present) AND carries a non-empty identifier/evidence ref. A receipt
     * that merely exists as an empty map, or an unapplied receipt, does not
     * count — promotion is not faked.
     */
    private function promotionReceiptPresent(mixed $receipt): bool
    {
        if (! is_array($receipt)) {
            return false;
        }

        $applied = ($receipt['applied'] ?? null) === true
            || ($receipt['present'] ?? null) === true;

        if (! $applied) {
            return false;
        }

        foreach (['receipt_id', 'id', 'evidence_ref', 'ref'] as $idKey) {
            $candidate = $receipt[$idKey] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Evidence ref for the promotion phase: a present receipt's own identifier,
     * else a deterministic met/blocked marker.
     */
    private function promotionEvidenceRef(mixed $receipt, string $prefix, bool $passed): string
    {
        if (is_array($receipt)) {
            foreach (['evidence_ref', 'ref', 'receipt_id', 'id'] as $idKey) {
                $candidate = $receipt[$idKey] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $passed ? $prefix.'met' : $prefix.'blocked';
    }
}

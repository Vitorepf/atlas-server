<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S97 — L7 Proof Bundle (operator-facing).
 *
 * Pure, read-only assembler for the operator proof bundle that answers a single
 * question honestly: has the runtime actually reached L7 Self-Evolving, or is it
 * still blocked? It composes the 8-item L7 arrival checklist from
 * atlas-aaeos-l7-convergence-roadmap.md ("Como saber que chegamos ao L7") into a
 * deterministic bundle with run_id, evidence_refs, blockers and final_status.
 *
 * Honesty rules (the operator does not accept false claims):
 *   - any missing/unmet checklist item yields final_status=blocked_not_l7;
 *   - the Product Mode summary states the real blockers; all_green is true only
 *     when zero blockers remain (no false all-green);
 *   - the proof summary path is materialised only when the command layer asks
 *     for it (called_by_command_layer=true); this assembler performs no I/O.
 *
 * It never invokes a provider, mutates a repo, merges, deploys, promotes a level
 * or touches the filesystem/clock/randomness. run_id is derived deterministically
 * from the inputs so the same evidence always produces the same bundle.
 */
final class L7ProofBundleService
{
    private const SCHEMA_VERSION = 'atlas.loop.l7_proof_bundle.v1';

    public const FINAL_STATUS_L7_READY = 'l7_ready';

    public const FINAL_STATUS_BLOCKED = 'blocked_not_l7';

    /**
     * Canonical L7 arrival checklist (phases 0..7), ordered.
     *
     * Each item maps the roadmap bullet to the input key that carries its
     * evidence and the blocker emitted when the item is not met.
     *
     * @var list<array{key: string, blocker: string, evidence_prefix: string}>
     */
    private const CHECKLIST = [
        ['key' => 'loop_executes_real', 'blocker' => 'loop_not_real_tier_scan_only', 'evidence_prefix' => 'evidence://l7/loop_executes_real/'],
        ['key' => 'ten_consecutive_cycles', 'blocker' => 'ten_consecutive_cycles_missing', 'evidence_prefix' => 'evidence://l7/ten_consecutive_cycles/'],
        ['key' => 'flywheel_proven', 'blocker' => 'compounding_flywheel_unproven', 'evidence_prefix' => 'evidence://l7/flywheel_proven/'],
        ['key' => 'departments_l4', 'blocker' => 'departments_below_l4', 'evidence_prefix' => 'evidence://l7/departments_l4/'],
        ['key' => 'http_path_mission_control', 'blocker' => 'http_path_or_mission_control_missing', 'evidence_prefix' => 'evidence://l7/http_path_mission_control/'],
        ['key' => 'self_construction_proven', 'blocker' => 'self_construction_proposals_incomplete', 'evidence_prefix' => 'evidence://l7/self_construction_proven/'],
        ['key' => 'trust_ledger_stable', 'blocker' => 'trust_ledger_below_threshold', 'evidence_prefix' => 'evidence://l7/trust_ledger_stable/'],
        ['key' => 'promotion_recorded', 'blocker' => 'promotion_receipt_missing', 'evidence_prefix' => 'evidence://l7/promotion_recorded/'],
    ];

    /**
     * Build the operator proof bundle from the L7 evidence inputs.
     *
     * Recognised `$inputs`:
     *   - checklist: array<string,mixed> keyed by the CHECKLIST keys; each entry
     *     may be a bool, or an array carrying `met`/`passed` (bool) and `evidence_ref`/`ref`.
     *   - called_by_command_layer: bool (default false) — only then is the proof
     *     summary path materialised.
     *   - run_label: string (optional) — folded into the deterministic run_id.
     *
     * @param  array<string,mixed>  $inputs
     * @return array<string,mixed>
     */
    public function build(array $inputs): array
    {
        $checklistInput = is_array($inputs['checklist'] ?? null) ? $inputs['checklist'] : [];

        $checklist = [];
        $evidenceRefs = [];
        $blockers = [];
        $metCount = 0;

        foreach (self::CHECKLIST as $phase => $item) {
            $key = $item['key'];
            $raw = $checklistInput[$key] ?? null;

            $met = $this->itemMet($raw);
            $evidenceRef = $this->evidenceRef($raw, $item['evidence_prefix'], $key, $met);

            $checklist[] = [
                'phase' => $phase,
                'key' => $key,
                'met' => $met,
                'evidence_ref' => $evidenceRef,
            ];

            if ($met) {
                $metCount++;
                $evidenceRefs[] = $evidenceRef;
            } else {
                $blockers[] = $item['blocker'];
            }
        }

        $checklistComplete = $blockers === [];
        $finalStatus = $checklistComplete ? self::FINAL_STATUS_L7_READY : self::FINAL_STATUS_BLOCKED;

        $calledByCommandLayer = ($inputs['called_by_command_layer'] ?? false) === true;
        $runId = $this->deriveRunId($inputs, $checklist);
        $summaryPath = $calledByCommandLayer ? $this->summaryPath($runId) : '';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'checklist' => $checklist,
            'checklist_total' => count(self::CHECKLIST),
            'checklist_met_count' => $metCount,
            'checklist_complete' => $checklistComplete,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $blockers,
            'final_status' => $finalStatus,
            'is_l7_ready' => $finalStatus === self::FINAL_STATUS_L7_READY,
            'summary_written' => $calledByCommandLayer,
            'summary_path' => $summaryPath,
            'product_mode_summary' => $this->productModeSummary($finalStatus, $metCount, $blockers),
        ];
    }

    /**
     * A checklist item is met only when its evidence explicitly asserts it.
     */
    private function itemMet(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            if (array_key_exists('met', $raw)) {
                return $raw['met'] === true;
            }

            if (array_key_exists('passed', $raw)) {
                return $raw['passed'] === true;
            }
        }

        return false;
    }

    /**
     * Resolve a stable evidence reference for one checklist item. A met item with
     * no explicit ref falls back to a deterministic prefixed ref; an unmet item
     * carries a blocked marker so the bundle never implies absent evidence.
     */
    private function evidenceRef(mixed $raw, string $prefix, string $key, bool $met): string
    {
        if (is_array($raw)) {
            foreach (['evidence_ref', 'ref'] as $refKey) {
                $candidate = $raw[$refKey] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (! $met) {
            return $prefix.'blocked';
        }

        return $prefix.'met';
    }

    /**
     * Deterministic run_id derived purely from the inputs and resolved checklist
     * outcome. No clock, no randomness: identical inputs map to one run_id.
     *
     * @param  array<string,mixed>  $inputs
     * @param  list<array<string,mixed>>  $checklist
     */
    private function deriveRunId(array $inputs, array $checklist): string
    {
        $label = is_string($inputs['run_label'] ?? null) ? (string) $inputs['run_label'] : '';

        $fingerprintSource = [];
        foreach ($checklist as $item) {
            $fingerprintSource[] = $item['key'].'='.($item['met'] ? '1' : '0');
        }

        $digest = md5($label."\n".implode('|', $fingerprintSource));

        return 'l7proof_'.substr($digest, 0, 16);
    }

    private function summaryPath(string $runId): string
    {
        return 'docs/engineering-knowledge-base/proof-bundles/'.$runId.'.md';
    }

    /**
     * Product Mode summary. all_green is true only with zero blockers; otherwise
     * it enumerates the honest blocker list so the operator is never shown a
     * false all-green.
     *
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function productModeSummary(string $finalStatus, int $metCount, array $blockers): array
    {
        $total = count(self::CHECKLIST);
        $allGreen = $blockers === [];

        $headline = $allGreen
            ? 'L7 reached: '.$metCount.'/'.$total.' checklist items met with evidence.'
            : 'Not L7 yet: '.$metCount.'/'.$total.' checklist items met, '.count($blockers).' blocker(s) outstanding.';

        return [
            'final_status' => $finalStatus,
            'all_green' => $allGreen,
            'items_met' => $metCount,
            'items_total' => $total,
            'blockers' => $blockers,
            'headline' => $headline,
            'honest' => true,
        ];
    }
}

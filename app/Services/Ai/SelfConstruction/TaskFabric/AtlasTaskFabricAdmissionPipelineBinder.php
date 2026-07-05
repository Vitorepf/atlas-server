<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure batch-pipeline binder: runs every candidate spec through the brutal
 * value admission gate and returns admitted specs, rejected specs, rejection
 * telemetry and an aggregate batch_value_score before any enqueue attempt.
 *
 * ADMISSION (first match per check, all applicable reasons collected — a candidate can fail
 * more than one check at once):
 *   semantic_duplicate    — target already present in known_targets (candidate's own or shared_facts)
 *   template_farm         — is_template_farm=true
 *   compound_impact_low   — compound_impact_score < COMPOUND_IMPACT_THRESHOLD
 *
 * value_score (admitted only) = compound_impact_score * (1 - give_back_risk_score).
 *
 * REJECTION TURNS INTO NEXT WORK, NOT SILENT DROP: every rejected candidate carries a
 * respec_action_plan mapping each gate_reason to the minimal originator repair, and the batch
 * exposes next_originator_actions — the deduplicated, sorted union of every repair action
 * needed across the whole rejected set, so the originator's next batch has a concrete todo list.
 *
 * INPUT:
 *   candidates[]   — each with task_packet_id, target, allowed_files, acceptance_criteria,
 *                    required_evidence, objective, compound_impact_score,
 *                    give_back_risk_score, known_targets, is_template_farm
 *   shared_facts?  — optional shared admission facts (known_targets, thresholds)
 *                    merged into every candidate before the gate runs
 *
 * OUTPUT:
 *   admitted[]              — specs that passed; preserves all original fields
 *   rejected[]              — specs that failed; includes gate_reasons[] and respec_action_plan[]
 *   rejection_summary       — count per rejection reason across the batch
 *   next_originator_actions — deduplicated, sorted repair actions across the whole rejected set
 *   batch_value_score       — avg value_score of admitted candidates (null if none admitted)
 *
 * PURE / DETERMINISTIC. Never enqueues, writes files, calls providers, or mutates state.
 */
final class AtlasTaskFabricAdmissionPipelineBinder
{
    public const SCHEMA = 'atlas.task_fabric.admission_pipeline_binder.v1';

    public const REASON_SEMANTIC_DUPLICATE = 'semantic_duplicate';

    public const REASON_TEMPLATE_FARM = 'template_farm';

    public const REASON_COMPOUND_IMPACT_LOW = 'compound_impact_low';

    public const REASON_SCOPE_NOT_MINIMAL = 'scope_not_minimal';

    /** compound_impact_score at/above this clears the impact bar on its own. */
    public const COMPOUND_IMPACT_THRESHOLD = 0.30;

    /** Deterministic, minimal originator repair per gate_reason. */
    private const RESPEC_ACTIONS = [
        self::REASON_SEMANTIC_DUPLICATE => 'merge_into_the_existing_target_or_pick_a_genuinely_new_target',
        self::REASON_TEMPLATE_FARM => 'diversify_objective_acceptance_and_evidence_shape_away_from_the_repeated_template',
        self::REASON_COMPOUND_IMPACT_LOW => 'add_at_least_one_measurable_compound_impact_signal_before_resubmitting',
        self::REASON_SCOPE_NOT_MINIMAL => 'narrow_allowed_files_to_specific_implementation_and_test_paths_with_no_bare_directories_or_wildcards',
    ];

    public function __construct(
        private readonly AtlasTaskFabricScopeMinimalityAuditor $scopeAuditor = new AtlasTaskFabricScopeMinimalityAuditor,
    ) {}

    /**
     * @param  array<string,mixed>  $input  candidates + optional shared_facts
     * @return array<string,mixed>
     */
    public function filter(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $sharedFacts = is_array($input['shared_facts'] ?? null) ? $input['shared_facts'] : [];

        $admitted = [];
        $rejected = [];
        $rejectionSummary = [];
        $nextOriginatorActions = [];
        $valueSum = 0.0;

        foreach ($candidates as $candidate) {
            $merged = array_merge($candidate, $sharedFacts);
            $gateReasons = $this->decide($merged);

            if ($gateReasons === []) {
                $impact = (float) ($candidate['compound_impact_score'] ?? 0.0);
                $risk = (float) ($candidate['give_back_risk_score'] ?? 0.0);
                $valueScore = round($impact * (1 - $risk), 4);

                $admitted[] = $this->preservedFields($candidate, $valueScore, $merged);
                $valueSum += $valueScore;
            } else {
                $rejected[] = array_merge(
                    $this->preservedFields($candidate, null, $merged),
                    [
                        'gate_reasons' => $gateReasons,
                        'respec_action_plan' => $this->respecActionPlan($gateReasons),
                    ],
                );
                foreach ($gateReasons as $reason) {
                    $rejectionSummary[$reason] = ($rejectionSummary[$reason] ?? 0) + 1;
                    $nextOriginatorActions[] = self::RESPEC_ACTIONS[$reason] ?? 'investigate_rejection_reason:'.$reason;
                }
            }
        }

        $nextOriginatorActions = array_values(array_unique($nextOriginatorActions));
        sort($nextOriginatorActions, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'admitted' => $admitted,
            'rejected' => $rejected,
            'rejection_summary' => $rejectionSummary,
            'next_originator_actions' => $nextOriginatorActions,
            'batch_value_score' => count($admitted) > 0 ? round($valueSum / count($admitted), 4) : null,
        ];
    }

    /**
     * Evaluates every applicable admission check and returns ALL matching gate_reasons — a
     * candidate can fail more than one check at once, and the originator needs to see every gap,
     * not just the first one found.
     *
     * @param  array<string,mixed>  $merged
     * @return list<string>
     */
    private function decide(array $merged): array
    {
        $reasons = [];

        $target = trim((string) ($merged['target'] ?? ''));
        $knownTargets = is_array($merged['known_targets'] ?? null) ? $merged['known_targets'] : [];
        if ($target !== '' && in_array($target, $knownTargets, true)) {
            $reasons[] = self::REASON_SEMANTIC_DUPLICATE;
        }

        if (($merged['is_template_farm'] ?? false) === true) {
            $reasons[] = self::REASON_TEMPLATE_FARM;
        }

        $impact = (float) ($merged['compound_impact_score'] ?? 0.0);
        if ($impact < self::COMPOUND_IMPACT_THRESHOLD) {
            $reasons[] = self::REASON_COMPOUND_IMPACT_LOW;
        }

        $scopeAudit = $this->scopeAuditor->audit([
            'allowed_files' => $merged['allowed_files'] ?? [],
            'forbidden_files' => $merged['forbidden_files'] ?? [],
        ]);
        if (! ($scopeAudit['scope_ok'] ?? true)) {
            $reasons[] = self::REASON_SCOPE_NOT_MINIMAL;
        }

        return $reasons;
    }

    /** @param  list<string>  $gateReasons @return array<string,string> */
    private function respecActionPlan(array $gateReasons): array
    {
        $plan = [];
        foreach ($gateReasons as $reason) {
            $plan[$reason] = self::RESPEC_ACTIONS[$reason] ?? 'investigate_rejection_reason:'.$reason;
        }

        return $plan;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  float|null  $valueScore  null for a rejected candidate (never admitted, so never scored)
     * @param  array<string,mixed>  $merged  candidate merged with shared_facts — the source of
     *                                       worker_floor/replenish_soon admission facts
     * @return array<string,mixed>
     */
    private function preservedFields(array $candidate, ?float $valueScore, array $merged = []): array
    {
        $fields = [
            'task_packet_id' => $candidate['task_packet_id'] ?? null,
            'target' => $candidate['target'] ?? null,
            'allowed_files' => $candidate['allowed_files'] ?? [],
            'acceptance_criteria' => $candidate['acceptance_criteria'] ?? [],
            'required_evidence' => $candidate['required_evidence'] ?? [],
            'objective' => $candidate['objective'] ?? '',
            'value_score' => $valueScore,
        ];

        // Worker-floor / replenish-soon admission facts, when supplied, ride along on the
        // receipt so later audits can distinguish emergency-but-valid replenishment (a
        // candidate admitted BECAUSE the worker floor was breached) from ordinary padding.
        if (array_key_exists('worker_floor', $merged)) {
            $fields['worker_floor'] = $merged['worker_floor'];
        }
        if (array_key_exists('replenish_soon', $merged)) {
            $fields['replenish_soon'] = $merged['replenish_soon'];
        }

        return $fields;
    }
}

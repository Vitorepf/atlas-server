<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure, fail-closed final gate: combines every simplification signal — cluster
 * evidence, boundary confidence, consumer impact, capability parity, proof
 * coverage, rollback reversibility, the live shadow plan, docs-drift sync, and
 * the cohesion metric — into one allow/hold/reject decision before a
 * consolidation task is permitted to delete, merge, or rewrite organs.
 *
 * Any missing core section, an irreversible rollback plan, or a cohesion metric
 * that shows the circuit getting worse rejects outright. Weak-but-present
 * confidence signals (boundary_confidence, proof_coverage) only hold for more
 * evidence rather than reject, since they are not proof of harm — just
 * insufficient proof of safety.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionSimplificationReadinessGate
{
    public const SCHEMA = 'atlas.self_construction.simplification_readiness_gate.v1';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_HOLD = 'hold';

    public const DECISION_REJECT = 'reject';

    private const BOUNDARY_CONFIDENCE_THRESHOLD = 0.70;

    private const PROOF_COVERAGE_THRESHOLD = 0.80;

    /** @var list<string> */
    private const CORE_SECTIONS = ['cluster', 'parity', 'shadow_plan', 'cohesion', 'rollback'];

    /** @var list<string> */
    private const DESTRUCTIVE_ACTION_TYPES = ['delete', 'merge'];

    /** @var list<string> */
    private const REQUIRED_EVIDENCE = [
        'cluster_overlap_evidence',
        'capability_parity_matrix',
        'live_shadow_comparison_report',
        'cohesion_before_after_metrics',
        'rollback_plan',
    ];

    /**
     * @param  array{
     *   cluster?: array{merge_ready?: bool},
     *   parity?: array{replacement_allowed?: bool},
     *   shadow_plan?: array{promotion_allowed?: bool},
     *   cohesion?: array{consolidation_improves_circuit?: bool},
     *   rollback?: array{reversible?: bool},
     *   docs_sync?: array{status?: string},
     *   boundary_confidence?: float,
     *   consumer_impact?: array{breaking_changes?: list<string>},
     *   proof_coverage?: float,
     *   safe_allowed_files?: list<string>,
     * }  $facts
     * @return array{
     *   schema: string,
     *   decision: string,
     *   blockers: list<string>,
     *   required_evidence: list<string>,
     *   safe_allowed_files: list<string>,
     *   irreversible_risks: list<string>,
     *   next_action: string,
     * }
     */
    public function evaluate(array $facts): array
    {
        $rejectBlockers = [];
        $holdBlockers = [];
        $irreversibleRisks = [];

        $missingSections = array_values(array_filter(
            self::CORE_SECTIONS,
            static fn (string $section): bool => ! array_key_exists($section, $facts) || ! is_array($facts[$section]),
        ));

        foreach ($missingSections as $section) {
            $rejectBlockers[] = 'missing_section:'.$section;
        }

        $rollback = (array) ($facts['rollback'] ?? []);
        $rollbackReversible = (bool) ($rollback['reversible'] ?? false);
        if (! in_array('rollback', $missingSections, true) && ! $rollbackReversible) {
            $rejectBlockers[] = 'rollback_not_reversible';
            $irreversibleRisks[] = 'organ_deletion_irreversible';
        }

        $cohesion = (array) ($facts['cohesion'] ?? []);
        if (! in_array('cohesion', $missingSections, true) && ! (bool) ($cohesion['consolidation_improves_circuit'] ?? false)) {
            $rejectBlockers[] = 'cohesion_does_not_improve_circuit';
        }

        $cluster = (array) ($facts['cluster'] ?? []);
        if (! in_array('cluster', $missingSections, true) && ! (bool) ($cluster['merge_ready'] ?? false)) {
            $rejectBlockers[] = 'cluster_not_merge_ready';
        }

        $parity = (array) ($facts['parity'] ?? []);
        if (! in_array('parity', $missingSections, true) && ! (bool) ($parity['replacement_allowed'] ?? false)) {
            $rejectBlockers[] = 'capability_parity_not_allowed';
        }

        $shadowPlan = (array) ($facts['shadow_plan'] ?? []);
        if (! in_array('shadow_plan', $missingSections, true) && ! (bool) ($shadowPlan['promotion_allowed'] ?? false)) {
            $rejectBlockers[] = 'shadow_plan_promotion_not_allowed';
        }

        $consumerImpact = (array) ($facts['consumer_impact'] ?? []);
        $breakingChanges = array_values((array) ($consumerImpact['breaking_changes'] ?? []));
        if ($breakingChanges !== []) {
            $rejectBlockers[] = 'consumer_breaking_changes_present';
        }

        $actionType = (string) ($facts['action_type'] ?? '');
        if (in_array($actionType, self::DESTRUCTIVE_ACTION_TYPES, true)) {
            if (! array_key_exists('regression_replay_plan', $facts) || ! is_array($facts['regression_replay_plan'])) {
                $rejectBlockers[] = 'regression_replay_plan_missing';
            } elseif (! (bool) ($facts['regression_replay_plan']['ready'] ?? false)) {
                $rejectBlockers[] = 'regression_replay_not_ready';
            }
        }

        $docsSync = (array) ($facts['docs_sync'] ?? []);
        $docsSyncStatus = (string) ($docsSync['status'] ?? '');
        if ($docsSyncStatus === 'blocked') {
            $rejectBlockers[] = 'docs_sync_blocked';
        }

        $boundaryConfidence = (float) ($facts['boundary_confidence'] ?? 0.0);
        if ($boundaryConfidence < self::BOUNDARY_CONFIDENCE_THRESHOLD) {
            $holdBlockers[] = 'boundary_confidence_below_threshold:'.number_format($boundaryConfidence, 2);
        }

        $proofCoverage = (float) ($facts['proof_coverage'] ?? 0.0);
        if ($proofCoverage < self::PROOF_COVERAGE_THRESHOLD) {
            $holdBlockers[] = 'proof_coverage_below_threshold:'.number_format($proofCoverage, 2);
        }

        if ($rejectBlockers !== []) {
            $decision = self::DECISION_REJECT;
            $nextAction = 'refuse_consolidation';
        } elseif ($holdBlockers !== []) {
            $decision = self::DECISION_HOLD;
            $nextAction = 'gather_more_evidence';
        } else {
            $decision = self::DECISION_ALLOW;
            $nextAction = 'proceed_with_consolidation';
        }

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'blockers' => array_merge($rejectBlockers, $holdBlockers),
            // blocker_ids are hard reject-tier evidence gaps; advisory_ids are hold-tier (weak-but-
            // present) signals that only ask for more evidence — kept distinct so a caller can never
            // confuse "must fix" with "gather more proof".
            'blocker_ids' => $rejectBlockers,
            'advisory_ids' => $holdBlockers,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'safe_allowed_files' => $decision === self::DECISION_ALLOW
                ? array_values((array) ($facts['safe_allowed_files'] ?? []))
                : [],
            'irreversible_risks' => $irreversibleRisks,
            'next_action' => $nextAction,
        ];
    }
}

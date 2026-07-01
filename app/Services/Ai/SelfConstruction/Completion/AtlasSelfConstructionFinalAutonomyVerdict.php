<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Final verdict composer for the Atlas Self-Construction OS. Combines:
 *   - the dependency audit verdict (AtlasSelfConstructionAutonomyDependencyAudit)
 *   - the transition map (AtlasSelfConstructionAutonomyTransitionMap)
 *   - a readiness policy verdict ({state:'ready'|'hold'|'blocked', blockers?:list<string>})
 *
 * into one of three named outcomes:
 *   - complete    : audit.atlas_native=true AND transition.untransitioned=[] AND readiness.state=ready
 *   - incomplete  : audit.atlas_native=true but transition has untransitioned dependencies OR readiness=hold
 *   - unsafe      : audit.atlas_native=false OR readiness.state=blocked
 *
 * Output (FACTS only):
 *   {schema_version, verdict, blockers, next_atlas_actions, asks_for_human:false}
 *
 * `asks_for_human` is ALWAYS false — even in unsafe verdicts, the next actions stay Atlas-native
 * (sandbox / extractor / replenishment), never operator/human rescue.
 */
final class AtlasSelfConstructionFinalAutonomyVerdict
{
    public const SCHEMA = 'atlas.self_construction.final_autonomy_verdict.v1';

    public const VERDICT_COMPLETE = 'complete';

    public const VERDICT_INCOMPLETE = 'incomplete';

    public const VERDICT_UNSAFE = 'unsafe';

    /** @var list<string> */
    public const REQUIRED_CAPABILITY_LANES = [
        'self_recovery',
        'balanced_lane_generation',
        'task_repair',
        'muscle_feedback_learning',
        'frontier_import',
        'compounding',
    ];

    /** Worker-feed evidence older than this is considered stale, never trusted for a final verdict. */
    private const WORKER_FEED_EVIDENCE_STALE_SECONDS = 3600;

    /** Soak-test evidence older than this is considered stale, never trusted for a final verdict. */
    private const SOAK_EVIDENCE_STALE_SECONDS = 86400;

    /**
     * @param  array<string,mixed>  $auditVerdict
     * @param  array<string,mixed>  $transitionMap
     * @param  array<string,mixed>  $readinessPolicy
     * @param  array<string,bool>  $capabilityFacts     Keyed by REQUIRED_CAPABILITY_LANES names; omit to skip check.
     * @param  array<string,list<string>>  $capabilityEvidence  Lane → evidence refs; when non-empty, true booleans without refs are insufficient.
     * @param  array<string,mixed>  $regressionFacts    {status:'pass'|'fail'|'pending', ...}. Omit to skip check (backward compat).
     * @param  array<string,mixed>  $workerFeedEvidence {age_seconds?:int, claimable_per_active_worker?:float,
     *         worker_feed_floor?:float, no_claimable_task_repaired?:bool}. Omit to skip check (backward compat).
     *         A final autonomy claim may NEVER assert worker-feed continuity is healthy from stale,
     *         missing, or sub-floor evidence — only fresh healthy floor metrics or an explicit
     *         no_claimable_task_repaired receipt satisfy this gate.
     * @param  array<string,mixed>  $soakEvidence {status?:'pass'|'fail', age_seconds?:int}.
     *         Unlike the other evidence params this is NEVER skippable: a final autonomy claim
     *         must always be backed by a fresh, passing soak run — readiness=ready alone is never
     *         sufficient proof that the system actually held up under sustained autonomous load.
     * @return array<string,mixed>
     */
    public function compose(array $auditVerdict, array $transitionMap, array $readinessPolicy, array $capabilityFacts = [], array $capabilityEvidence = [], array $regressionFacts = [], array $workerFeedEvidence = [], array $soakEvidence = []): array
    {
        $atlasNative = (bool) ($auditVerdict['atlas_native'] ?? false);
        $auditBlockers = array_values((array) ($auditVerdict['blockers'] ?? []));
        $untransitioned = array_values((array) ($transitionMap['untransitioned'] ?? []));
        $replacements = array_values((array) ($transitionMap['replacements'] ?? []));
        $readinessState = (string) ($readinessPolicy['state'] ?? 'unknown');
        $readinessBlockers = array_values((array) ($readinessPolicy['blockers'] ?? []));

        $missingLanes = [];
        $lanesWithoutEvidence = [];
        $score = 0;
        if ($capabilityFacts !== []) {
            foreach (self::REQUIRED_CAPABILITY_LANES as $lane) {
                if (! (bool) ($capabilityFacts[$lane] ?? false)) {
                    $missingLanes[] = $lane;
                } elseif ($capabilityEvidence !== [] && empty($capabilityEvidence[$lane])) {
                    $lanesWithoutEvidence[] = $lane;
                }
            }
            $metCount = count(self::REQUIRED_CAPABILITY_LANES) - count($missingLanes);
            $score = (int) floor($metCount / count(self::REQUIRED_CAPABILITY_LANES) * 100);
        }

        $regressionBlockers = [];
        $evidenceDemands = [];
        if ($regressionFacts !== []) {
            $regStatus = (string) ($regressionFacts['status'] ?? 'missing');
            if ($regStatus !== 'pass') {
                $regressionBlockers[] = 'regression_not_passed:'.($regStatus === '' ? 'missing' : $regStatus);
                $evidenceDemands[] = 'provide_regression_test_results_with_status_pass';
            }
        }
        foreach ($missingLanes as $lane) {
            $evidenceDemands[] = 'provision_capability_lane:'.$lane;
        }
        foreach ($lanesWithoutEvidence as $lane) {
            $evidenceDemands[] = 'collect_evidence_for_lane:'.$lane;
        }

        // Build readiness_95_blockers — emitted on every verdict for downstream consumers.
        $readiness95Blockers = [];
        foreach ($missingLanes as $lane) {
            $readiness95Blockers[] = ['lane' => $lane, 'type' => 'missing_lane', 'next_action' => 'provision_capability_lane:'.$lane];
        }
        foreach ($lanesWithoutEvidence as $lane) {
            $readiness95Blockers[] = ['lane' => $lane, 'type' => 'missing_evidence', 'next_action' => 'collect_evidence_for_lane:'.$lane];
        }

        $blockers = [];
        $nextActions = [];

        // UNSAFE — non-atlas-native steady-state dependency OR readiness blocked.
        if (! $atlasNative || $readinessState === 'blocked') {
            foreach ($auditBlockers as $b) {
                $blockers[] = 'audit:'.(string) $b;
            }
            if ($readinessState === 'blocked') {
                foreach ($readinessBlockers as $b) {
                    $blockers[] = 'readiness:'.(string) $b;
                }
            }
            foreach ($replacements as $r) {
                $nextActions[] = (string) ($r['task_fabric_action'] ?? '');
            }
            $nextActions[] = 'route_atlas_native_replacement_capabilities';

            return $this->envelope(self::VERDICT_UNSAFE, $blockers, $nextActions, $score, $readiness95Blockers, $evidenceDemands);
        }

        // INCOMPLETE — missing capability lanes OR lanes lacking evidence refs.
        if ($missingLanes !== [] || $lanesWithoutEvidence !== []) {
            foreach ($missingLanes as $lane) {
                $blockers[] = 'missing_capability_lane:'.$lane;
            }
            foreach ($lanesWithoutEvidence as $lane) {
                $blockers[] = 'missing_evidence_for_lane:'.$lane;
            }
            foreach ($replacements as $r) {
                $nextActions[] = (string) ($r['task_fabric_action'] ?? '');
            }
            $nextActions[] = $missingLanes !== [] ? 'provision_missing_capability_lanes' : 'collect_missing_lane_evidence';

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score, $readiness95Blockers, $evidenceDemands);
        }

        // INCOMPLETE — atlas_native AND not blocked, but untransitioned deps OR readiness not explicitly ready.
        if ($untransitioned !== [] || $readinessState !== 'ready') {
            foreach ($untransitioned as $u) {
                $blockers[] = 'untransitioned:'.(string) ($u['step_id'] ?? '');
            }
            if ($readinessState === 'hold') {
                $blockers[] = 'readiness:hold';
                foreach ($readinessBlockers as $b) {
                    $blockers[] = 'readiness_hold:'.(string) $b;
                }
            } elseif ($readinessState !== 'ready') {
                // 'unknown' or any other non-explicit state: gate must not pass
                $blockers[] = 'readiness:'.$readinessState;
            }
            foreach ($replacements as $r) {
                $nextActions[] = (string) ($r['task_fabric_action'] ?? '');
            }
            $nextActions[] = 'extend_transition_map_for_unknown_steps';

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score, [], $evidenceDemands);
        }

        // INCOMPLETE — regression facts provided but not passing.
        if ($regressionBlockers !== []) {
            $blockers = $regressionBlockers;
            $nextActions[] = 'resolve_regression_failures_before_final_ready';

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score, [], $evidenceDemands);
        }

        // INCOMPLETE — soak evidence is mandatory (never skippable): missing, stale, or failed.
        $soakReason = $this->soakUnhealthyReason($soakEvidence);
        if ($soakReason !== null) {
            $blockers = ['soak_evidence:'.$soakReason];
            $nextActions[] = 'refresh_soak_test_evidence';
            $evidenceDemands[] = 'provide_fresh_passing_soak_evidence';

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score, [], $evidenceDemands);
        }

        // INCOMPLETE — worker-feed continuity evidence provided but stale, missing, or below floor.
        // A final autonomy claim can never assert workers are being fed from evidence that can't
        // prove it right now.
        if ($workerFeedEvidence !== []) {
            $workerFeedReason = $this->workerFeedUnhealthyReason($workerFeedEvidence);
            if ($workerFeedReason !== null) {
                $blockers = ['worker_feed_evidence:'.$workerFeedReason];
                $nextActions[] = 'refresh_worker_feed_continuity_evidence';
                $evidenceDemands[] = 'provide_fresh_worker_feed_floor_metrics_or_repaired_no_claimable_task_receipt';

                return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score, [], $evidenceDemands);
            }
        }

        return $this->envelope(self::VERDICT_COMPLETE, [], [], $score, [], $evidenceDemands);
    }

    /**
     * Returns null when worker-feed evidence is trustworthy enough for a final claim; otherwise a
     * machine-readable reason string. Repaired no_claimable_task receipts satisfy the gate even
     * when the raw floor ratio is thin — a repair receipt IS the fresh healthy signal.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function workerFeedUnhealthyReason(array $evidence): ?string
    {
        if (! array_key_exists('age_seconds', $evidence)) {
            return 'missing_age';
        }
        $ageSeconds = (int) $evidence['age_seconds'];
        if ($ageSeconds > self::WORKER_FEED_EVIDENCE_STALE_SECONDS) {
            return 'stale';
        }

        if ((bool) ($evidence['no_claimable_task_repaired'] ?? false)) {
            return null;
        }

        if (! array_key_exists('claimable_per_active_worker', $evidence) || ! array_key_exists('worker_feed_floor', $evidence)) {
            return 'missing_floor_metrics';
        }
        $claimablePerActiveWorker = (float) $evidence['claimable_per_active_worker'];
        $workerFeedFloor = (float) $evidence['worker_feed_floor'];
        if ($claimablePerActiveWorker < $workerFeedFloor) {
            return 'below_floor';
        }

        return null;
    }

    /**
     * Never skippable: a final autonomy claim always requires fresh, passing soak evidence.
     * Missing evidence entirely reads as 'missing_soak_evidence', matching AC1 verbatim.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function soakUnhealthyReason(array $evidence): ?string
    {
        if ($evidence === [] || ! array_key_exists('status', $evidence)) {
            return 'missing_soak_evidence';
        }

        if (array_key_exists('age_seconds', $evidence) && (int) $evidence['age_seconds'] > self::SOAK_EVIDENCE_STALE_SECONDS) {
            return 'stale_soak_evidence';
        }

        if ((string) $evidence['status'] !== 'pass') {
            return 'failed_soak_evidence';
        }

        return null;
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @param  list<array{lane:string,type:string,next_action:string}>  $readiness95Blockers
     * @param  list<string>  $evidenceDemands
     * @return array<string,mixed>
     */
    private function envelope(string $verdict, array $blockers, array $nextActions, int $score = 0, array $readiness95Blockers = [], array $evidenceDemands = []): array
    {
        return [
            'schema_version'       => self::SCHEMA,
            'verdict'              => $verdict,
            'blockers'             => array_values(array_unique($blockers)),
            'next_atlas_actions'   => array_values(array_unique(array_filter($nextActions, static fn (string $s): bool => $s !== ''))),
            'asks_for_human'       => false,
            'score'                => $score,
            'readiness_95_blockers' => $readiness95Blockers,
            'next_evidence_demands' => array_values(array_unique($evidenceDemands)),
        ];
    }
}

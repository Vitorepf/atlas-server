<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\AutonomousRuntime;

/**
 * Pure gate. Decides whether the autonomous self-construction runtime should STOP, HOLD, or CONTINUE.
 *
 * INPUT FACTS (no provider call, no worker self-report — only court/governor/queue facts):
 *   { verification_court:{verdict?:string, server_side_green?:bool},
 *     merge_governor:{decision?:string},
 *     rollback_gate:{conformant?:bool},
 *     malformed_task_sweep:{count?:int, sample?:list<string>},
 *     give_back_class:{repeated_class?:string|null, repeated_count?:int},
 *     scope_drift:{count?:int},
 *     context_freshness:{conformant?:bool, blockers?:list<string>} }
 *
 * OUTPUT:
 *   { schema, action ∈ {stop,hold,continue}, reasons:list<string>, evidence:array }
 *
 * STOP CLASSES (any present ⇒ stop):
 *   - verification_court_red
 *   - rollback_missing
 *   - malformed_task_sweep:<count>
 *   - repeated_give_back_class:<class>:<count>
 *   - scope_drift:<count>
 *   - merge_governor_rejected
 *   - poison_risk:<class>            (poison_signal.detected=true)
 *   - stale_proof:<age_hours>        (proof_freshness.conformant=false — verification evidence itself is stale)
 *   - runaway_growth:<rate>          (growth_metrics.queue_growth_rate > growth_metrics.ceiling)
 *   - lease_mismatch                 (lease_integrity.matches=false)
 *
 * HOLD CLASSES (no STOPs but at least one ⇒ hold):
 *   - context_freshness_blocked
 *
 * RESUME (see {@see resume()}): requires non-empty repair_evidence_refs AND, when
 * original_stop_reasons + current_evaluate_facts are supplied, re-runs evaluate() against the
 * current facts to prove none of the original stop reasons are still present — a resume can never
 * proceed on the claim that a stop cause was fixed without re-checking it.
 *
 * INVARIANTS:
 *   - NEVER treats worker self-report as final safety evidence.
 *   - DETERMINISTIC envelope (reasons sorted).
 *   - PURE.
 */
final class AtlasAutonomousRuntimeSafetyStopGate
{
    public const SCHEMA = 'atlas.autonomousruntime.safety_stop.v1';

    public const ACTION_STOP = 'stop';

    public const ACTION_HOLD = 'hold';

    public const ACTION_CONTINUE = 'continue';

    public const REPEATED_GIVE_BACK_THRESHOLD = 3;

    public const ACTION_OBSERVE = 'observe';

    /**
     * Resume gate — decides whether the autonomous runtime may EXIT a safety stop.
     *
     * Required to exit: atlas_native_resume_proof (non-empty array), queue_health (truthy),
     * rollback_readiness (truthy), unsafe_release_active must be false, AND
     * repair_evidence_refs (non-empty list of evidence identifiers) — a resume is never allowed
     * on a bare claim of repair without a runnable/traceable evidence reference.
     *
     * When original_stop_reasons + current_evaluate_facts are supplied, this re-runs evaluate()
     * against the current facts and blocks resume if any original stop reason is still present —
     * repair_evidence_refs alone is never trusted without re-checking the actual cause.
     *
     * If all conditions pass, action='observe' (cautious post-stop state). Otherwise action='stop'.
     *
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, action:string, resume_allowed:bool, blockers:list<string>}
     */
    public function resume(array $facts): array
    {
        $blockers = [];

        $proof = $facts['atlas_native_resume_proof'] ?? null;
        if (! is_array($proof) || $proof === []) {
            $blockers[] = 'atlas_native_resume_proof_missing';
        }

        if (! (bool) ($facts['queue_health'] ?? false)) {
            $blockers[] = 'queue_health_unhealthy';
        }

        if (! (bool) ($facts['rollback_readiness'] ?? false)) {
            $blockers[] = 'rollback_unready';
        }

        if ((bool) ($facts['unsafe_release_active'] ?? false)) {
            $blockers[] = 'unsafe_release_active';
        }

        $repairEvidenceRefs = array_values(array_filter(
            array_map('strval', (array) ($facts['repair_evidence_refs'] ?? [])),
            static fn (string $ref): bool => $ref !== '',
        ));
        if ($repairEvidenceRefs === []) {
            $blockers[] = 'repair_evidence_refs_missing';
        }

        $originalStopReasons = array_values(array_filter(
            array_map('strval', (array) ($facts['original_stop_reasons'] ?? [])),
            static fn (string $r): bool => $r !== '',
        ));
        if ($originalStopReasons !== []) {
            $currentFacts = is_array($facts['current_evaluate_facts'] ?? null) ? $facts['current_evaluate_facts'] : [];
            $freshReasons = $this->evaluate($currentFacts)['reasons'];
            foreach (array_intersect($originalStopReasons, $freshReasons) as $stillPresent) {
                $blockers[] = 'original_stop_reason_still_present:'.$stillPresent;
            }
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'action' => $blockers === [] ? self::ACTION_OBSERVE : self::ACTION_STOP,
            'resume_allowed' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $facts
     * @return array{schema:string, action:string, reasons:list<string>, evidence:array<string,mixed>}
     */
    public function evaluate(array $facts): array
    {
        $stop = [];
        $hold = [];

        // STOP: verification court red.
        $court = is_array($facts['verification_court'] ?? null) ? $facts['verification_court'] : [];
        $verdict = (string) ($court['verdict'] ?? '');
        $ssg = (bool) ($court['server_side_green'] ?? false);
        if ($verdict === 'failed' || ($verdict !== '' && ! $ssg)) {
            $stop[] = 'verification_court_red';
        }

        // STOP: rollback missing.
        $rollback = is_array($facts['rollback_gate'] ?? null) ? $facts['rollback_gate'] : [];
        if (! (bool) ($rollback['conformant'] ?? false)) {
            $stop[] = 'rollback_missing';
        }

        // STOP: malformed task sweep (any malformed packet detected).
        $malformed = is_array($facts['malformed_task_sweep'] ?? null) ? $facts['malformed_task_sweep'] : [];
        $malformedCount = (int) ($malformed['count'] ?? 0);
        if ($malformedCount > 0) {
            $stop[] = 'malformed_task_sweep:'.$malformedCount;
        }

        // STOP: repeated give-back class.
        $gb = is_array($facts['give_back_class'] ?? null) ? $facts['give_back_class'] : [];
        $gbClass = (string) ($gb['repeated_class'] ?? '');
        $gbCount = (int) ($gb['repeated_count'] ?? 0);
        if ($gbClass !== '' && $gbCount >= self::REPEATED_GIVE_BACK_THRESHOLD) {
            $stop[] = 'repeated_give_back_class:'.$gbClass.':'.$gbCount;
        }

        // STOP: scope drift.
        $scope = is_array($facts['scope_drift'] ?? null) ? $facts['scope_drift'] : [];
        $driftCount = (int) ($scope['count'] ?? 0);
        if ($driftCount > 0) {
            $stop[] = 'scope_drift:'.$driftCount;
        }

        // STOP: merge governor rejected.
        $mg = is_array($facts['merge_governor'] ?? null) ? $facts['merge_governor'] : [];
        $mgDecision = (string) ($mg['decision'] ?? '');
        if (in_array($mgDecision, ['rejected', 'blocked'], true)) {
            $stop[] = 'merge_governor_rejected';
        }

        // STOP: poison risk detected.
        $poison = is_array($facts['poison_signal'] ?? null) ? $facts['poison_signal'] : [];
        $poisonDetected = (bool) ($poison['detected'] ?? false);
        $poisonClass = (string) ($poison['class'] ?? 'unknown');
        if ($poisonDetected) {
            $stop[] = 'poison_risk:'.$poisonClass;
        }

        // STOP: stale proof — the verification evidence itself is too old to trust, distinct
        // from context_freshness (docs/context pack), which is a HOLD, not a STOP.
        $proofFreshness = is_array($facts['proof_freshness'] ?? null) ? $facts['proof_freshness'] : [];
        $proofConformant = (bool) ($proofFreshness['conformant'] ?? true);
        $proofAgeHours = (float) ($proofFreshness['age_hours'] ?? 0.0);
        if (isset($proofFreshness['conformant']) && ! $proofConformant) {
            $stop[] = 'stale_proof:'.$proofAgeHours;
        }

        // STOP: runaway growth — queue growth rate exceeds its safety ceiling.
        $growth = is_array($facts['growth_metrics'] ?? null) ? $facts['growth_metrics'] : [];
        $growthRate = (float) ($growth['queue_growth_rate'] ?? 0.0);
        $growthCeiling = (float) ($growth['ceiling'] ?? INF);
        if ($growthRate > $growthCeiling) {
            $stop[] = 'runaway_growth:'.$growthRate;
        }

        // STOP: lease mismatch — the runtime's lease no longer matches the authoritative record.
        $leaseIntegrity = is_array($facts['lease_integrity'] ?? null) ? $facts['lease_integrity'] : [];
        $leaseMatches = (bool) ($leaseIntegrity['matches'] ?? true);
        if (isset($leaseIntegrity['matches']) && ! $leaseMatches) {
            $stop[] = 'lease_mismatch';
        }

        // HOLD: context freshness blocked.
        $cf = is_array($facts['context_freshness'] ?? null) ? $facts['context_freshness'] : [];
        if (isset($cf['conformant']) && ! (bool) $cf['conformant']) {
            $hold[] = 'context_freshness_blocked';
            foreach ((array) ($cf['blockers'] ?? []) as $b) {
                $hold[] = 'context_freshness:'.(string) $b;
            }
        }

        $allReasons = array_merge($stop, $hold);
        $allReasons = array_values(array_unique($allReasons));
        sort($allReasons, SORT_STRING);

        $action = self::ACTION_CONTINUE;
        if ($stop !== []) {
            $action = self::ACTION_STOP;
        } elseif ($hold !== []) {
            $action = self::ACTION_HOLD;
        }

        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reasons' => $allReasons,
            'evidence' => [
                'court_verdict' => $verdict,
                'court_server_side_green' => $ssg,
                'rollback_conformant' => (bool) ($rollback['conformant'] ?? false),
                'malformed_count' => $malformedCount,
                'give_back_repeated_class' => $gbClass,
                'give_back_repeated_count' => $gbCount,
                'scope_drift_count' => $driftCount,
                'merge_governor_decision' => $mgDecision,
                'context_freshness_conformant' => (bool) ($cf['conformant'] ?? false),
                'poison_detected' => $poisonDetected,
                'proof_freshness_conformant' => $proofConformant,
                'growth_rate' => $growthRate,
                'lease_matches' => $leaseMatches,
            ],
        ];
    }
}

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

    /**
     * @param  array<string,mixed>  $auditVerdict
     * @param  array<string,mixed>  $transitionMap
     * @param  array<string,mixed>  $readinessPolicy
     * @param  array<string,bool>  $capabilityFacts     Keyed by REQUIRED_CAPABILITY_LANES names; omit to skip check.
     * @param  array<string,list<string>>  $capabilityEvidence  Lane → evidence refs; when non-empty, true booleans without refs are insufficient.
     * @return array<string,mixed>
     */
    public function compose(array $auditVerdict, array $transitionMap, array $readinessPolicy, array $capabilityFacts = [], array $capabilityEvidence = []): array
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

            return $this->envelope(self::VERDICT_UNSAFE, $blockers, $nextActions, $score, $readiness95Blockers);
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

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score, $readiness95Blockers);
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

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions, $score);
        }

        return $this->envelope(self::VERDICT_COMPLETE, [], [], $score);
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @param  list<array{lane:string,type:string,next_action:string}>  $readiness95Blockers
     * @return array<string,mixed>
     */
    private function envelope(string $verdict, array $blockers, array $nextActions, int $score = 0, array $readiness95Blockers = []): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'blockers' => array_values(array_unique($blockers)),
            'next_atlas_actions' => array_values(array_unique(array_filter($nextActions, static fn (string $s): bool => $s !== ''))),
            'asks_for_human' => false,
            'score' => $score,
            'readiness_95_blockers' => $readiness95Blockers,
        ];
    }
}

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

    /**
     * @param  array<string,mixed>  $auditVerdict
     * @param  array<string,mixed>  $transitionMap
     * @param  array<string,mixed>  $readinessPolicy
     * @return array<string,mixed>
     */
    public function compose(array $auditVerdict, array $transitionMap, array $readinessPolicy): array
    {
        $atlasNative = (bool) ($auditVerdict['atlas_native'] ?? false);
        $auditBlockers = array_values((array) ($auditVerdict['blockers'] ?? []));
        $untransitioned = array_values((array) ($transitionMap['untransitioned'] ?? []));
        $replacements = array_values((array) ($transitionMap['replacements'] ?? []));
        $readinessState = (string) ($readinessPolicy['state'] ?? 'unknown');
        $readinessBlockers = array_values((array) ($readinessPolicy['blockers'] ?? []));

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

            return $this->envelope(self::VERDICT_UNSAFE, $blockers, $nextActions);
        }

        // INCOMPLETE — atlas_native AND not blocked, but still untransitioned deps OR readiness on hold.
        if ($untransitioned !== [] || $readinessState === 'hold') {
            foreach ($untransitioned as $u) {
                $blockers[] = 'untransitioned:'.(string) ($u['step_id'] ?? '');
            }
            if ($readinessState === 'hold') {
                $blockers[] = 'readiness:hold';
                foreach ($readinessBlockers as $b) {
                    $blockers[] = 'readiness_hold:'.(string) $b;
                }
            }
            foreach ($replacements as $r) {
                $nextActions[] = (string) ($r['task_fabric_action'] ?? '');
            }
            $nextActions[] = 'extend_transition_map_for_unknown_steps';

            return $this->envelope(self::VERDICT_INCOMPLETE, $blockers, $nextActions);
        }

        return $this->envelope(self::VERDICT_COMPLETE, [], []);
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @return array<string,mixed>
     */
    private function envelope(string $verdict, array $blockers, array $nextActions): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'blockers' => array_values(array_unique($blockers)),
            'next_atlas_actions' => array_values(array_unique(array_filter($nextActions, static fn (string $s): bool => $s !== ''))),
            'asks_for_human' => false,
        ];
    }
}

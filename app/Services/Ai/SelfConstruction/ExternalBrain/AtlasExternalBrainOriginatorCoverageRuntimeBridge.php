<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure bridge: translates an AtlasExternalBrainOriginatorCoverageCommand verdict
 * (route_to_gaps, roadmap_coverage, surface_saturation, backlog_aging,
 * impact_diversity) into a deterministic next-originator routing decision — no
 * provider call, no operator input.
 *
 * When the coverage verdict says route_to_gaps=true, the bridge names the
 * undercovered high-priority gaps the next batch should target instead of
 * continuing to mine the current (spent or overcovered) theme.
 *
 * ACTION (priority — first match wins; new field, additive on top of pivot_required):
 *   self_heal        — queue_health.malformed_rate or .poison_rate ≥ 0.30 (or explicit
 *                       queue_health.poison_heavy=true). A poisoned/malformed queue must be
 *                       repaired before any new volume is originated.
 *   retire_or_refresh — backlog_aging names a stale/overcovered theme (decision is retire or
 *                       respec, or overcovered_stale=true). Old, already-mined coverage should
 *                       be retired or refreshed rather than re-mined.
 *   originate         — route_to_gaps=true AND at least one target gap is named. target_lane
 *                       names the lane to originate into; evidence_gap names the proof driving it.
 *   research          — surface_saturation.verdict is 'deepen' (or absent): the surface still has
 *                       signal, keep searching before committing to a theme.
 *   consolidate        — default: no strong signal in any direction, consolidate current work.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainOriginatorCoverageRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.originator_coverage_runtime_bridge.v1';

    public const ACTION_SELF_HEAL = 'self_heal';

    public const ACTION_RETIRE_OR_REFRESH = 'retire_or_refresh';

    public const ACTION_ORIGINATE = 'originate';

    public const ACTION_RESEARCH = 'research';

    public const ACTION_CONSOLIDATE = 'consolidate';

    private const POISON_RATE_THRESHOLD = 0.30;

    /**
     * @param  array{
     *   route_to_gaps?: bool,
     *   reasons?: list<string>,
     *   roadmap_coverage?: array{next_batch_should_target?: list<string>, undercovered_high_priority_gaps?: list<string>},
     *   surface_saturation?: array{verdict?: string},
     *   backlog_aging?: array{decision?: string, overcovered_stale?: bool, target?: string},
     *   queue_health?: array{malformed_rate?: float, poison_rate?: float, poison_heavy?: bool},
     * }  $coverageVerdict
     * @return array{schema:string, pivot_required:bool, target_gaps:list<string>, next_theme:?string,
     *   reasons:list<string>, action:string, target_lane:?string, evidence_gap:?string}
     */
    public function route(array $coverageVerdict): array
    {
        $pivotRequired = (bool) ($coverageVerdict['route_to_gaps'] ?? false);
        $reasons = array_values((array) ($coverageVerdict['reasons'] ?? []));

        $roadmapCoverage = (array) ($coverageVerdict['roadmap_coverage'] ?? []);
        $targetGaps = array_values((array) (
            $roadmapCoverage['next_batch_should_target']
            ?? $roadmapCoverage['undercovered_high_priority_gaps']
            ?? []
        ));
        $targetGaps = $pivotRequired ? $targetGaps : [];
        $nextTheme = $pivotRequired ? ($targetGaps[0] ?? null) : null;

        $queueHealth = (array) ($coverageVerdict['queue_health'] ?? []);
        $malformedRate = (float) ($queueHealth['malformed_rate'] ?? 0.0);
        $poisonRate = (float) ($queueHealth['poison_rate'] ?? 0.0);
        $poisonHeavy = $malformedRate >= self::POISON_RATE_THRESHOLD
            || $poisonRate >= self::POISON_RATE_THRESHOLD
            || (bool) ($queueHealth['poison_heavy'] ?? false);

        $backlogAging = (array) ($coverageVerdict['backlog_aging'] ?? []);
        $backlogDecision = (string) ($backlogAging['decision'] ?? '');
        $staleOvercovered = in_array($backlogDecision, ['retire', 'respec'], true)
            || (bool) ($backlogAging['overcovered_stale'] ?? false);

        $surfaceSaturation = (array) ($coverageVerdict['surface_saturation'] ?? []);
        $surfaceVerdict = (string) ($surfaceSaturation['verdict'] ?? '');

        $evidenceGap = $targetGaps !== [] ? implode(',', $targetGaps) : ($reasons[0] ?? null);

        $action = match (true) {
            $poisonHeavy => self::ACTION_SELF_HEAL,
            $staleOvercovered => self::ACTION_RETIRE_OR_REFRESH,
            $pivotRequired && $targetGaps !== [] => self::ACTION_ORIGINATE,
            $surfaceVerdict === '' || $surfaceVerdict === 'deepen' => self::ACTION_RESEARCH,
            default => self::ACTION_CONSOLIDATE,
        };

        $targetLane = $action === self::ACTION_ORIGINATE ? $nextTheme : null;

        return [
            'schema' => self::SCHEMA,
            'pivot_required' => $pivotRequired,
            'target_gaps' => $targetGaps,
            'next_theme' => $nextTheme,
            'reasons' => $reasons,
            'action' => $action,
            'target_lane' => $targetLane,
            'evidence_gap' => $action === self::ACTION_ORIGINATE ? $evidenceGap : null,
        ];
    }
}

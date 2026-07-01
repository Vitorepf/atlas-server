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
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainOriginatorCoverageRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.originator_coverage_runtime_bridge.v1';

    /**
     * @param  array{
     *   route_to_gaps?: bool,
     *   reasons?: list<string>,
     *   roadmap_coverage?: array{next_batch_should_target?: list<string>, undercovered_high_priority_gaps?: list<string>},
     * }  $coverageVerdict
     * @return array{schema:string, pivot_required:bool, target_gaps:list<string>, next_theme:?string, reasons:list<string>}
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

        return [
            'schema' => self::SCHEMA,
            'pivot_required' => $pivotRequired,
            'target_gaps' => $pivotRequired ? $targetGaps : [],
            'next_theme' => $pivotRequired ? ($targetGaps[0] ?? null) : null,
            'reasons' => $reasons,
        ];
    }
}

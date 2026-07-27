<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsFileOption;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogAgingValueMonitor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorImpactDiversityReport;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRoadmapCoverageGapGovernor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSurfaceSaturationMeter;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator surface: atlas:external-brain:originator-coverage
 *
 * Combines four already-pure ExternalBrain organs into one governance
 * verdict so the originator stops mining exhausted themes and routes new
 * batches toward under-covered structural gaps instead:
 *
 *   - {@see AtlasExternalBrainRoadmapCoverageGapGovernor}      — which roadmap gaps are under/over covered
 *   - {@see AtlasExternalBrainSurfaceSaturationMeter}          — whether the current search surface is spent
 *   - {@see AtlasExternalBrainBacklogAgingValueMonitor}        — which queued tasks have decayed and should
 *                                                                 not count as real roadmap coverage
 *   - {@see AtlasExternalBrainOriginatorImpactDiversityReport} — whether the batch advances diverse capability
 *
 * Pure composition: no enqueue, no queue mutation, no provider calls. All
 * facts are supplied via a JSON facts file.
 *
 * Output (always JSON):
 *   schema, route_to_gaps, reasons, roadmap_coverage, surface_saturation,
 *   backlog_aging, impact_diversity
 */
final class AtlasExternalBrainOriginatorCoverageCommand extends Command
{
    use LoadsFactsFileOption;

    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.originator_coverage.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:originator-coverage
        {--facts-file= : Path to a JSON facts file}
        {--json : Emit JSON output (always on)}';

    /** @var string */
    protected $description = 'Read-only: combine roadmap coverage, surface saturation, backlog aging, and impact diversity into one governance verdict.';

    public function handle(): int
    {
        $facts = $this->loadFacts();

        $roadmapGovernor = new AtlasExternalBrainRoadmapCoverageGapGovernor;
        $saturationMeter = new AtlasExternalBrainSurfaceSaturationMeter;
        $backlogAgingMonitor = new AtlasExternalBrainBacklogAgingValueMonitor;
        $impactDiversityReport = new AtlasExternalBrainOriginatorImpactDiversityReport;

        // Stale/retired backlog tasks never count as real roadmap coverage — filter them out of
        // the "queued_tasks" fact BEFORE the roadmap governor counts coverage, so a pile of dead
        // backlog cannot make an under-covered gap look already-served.
        $backlogAging = $backlogAgingMonitor->evaluate([
            'tasks' => $facts['backlog_tasks'] ?? [],
            'now' => $facts['now'] ?? '',
        ]);
        $retiredTaskIds = array_column($backlogAging['retire_candidates'], 'task_id');
        $queuedTasks = array_values(array_filter(
            (array) ($facts['queued_tasks'] ?? []),
            static fn ($task): bool => is_array($task) && ! in_array((string) ($task['task_id'] ?? ''), $retiredTaskIds, true),
        ));

        $roadmapCoverage = $roadmapGovernor->govern([
            'roadmap_gaps' => $facts['roadmap_gaps'] ?? [],
            'queued_tasks' => $queuedTasks,
            'completed_capabilities' => $facts['completed_capabilities'] ?? [],
            'candidate_batches' => $facts['candidate_batches'] ?? [],
            'claimable_per_active_worker' => $facts['claimable_per_active_worker'] ?? null,
        ]);

        $surfaceSaturation = $saturationMeter->measure(
            (string) ($facts['surface_id'] ?? 'default'),
            is_array($facts['recent_candidates'] ?? null) ? $facts['recent_candidates'] : [],
            is_array($facts['surface_context'] ?? null) ? $facts['surface_context'] : [],
        );

        $impactDiversity = $impactDiversityReport->report([
            'tasks' => $facts['candidate_batches'] ?? [],
            'high_priority_classes' => $facts['high_priority_classes'] ?? null,
        ]);

        // Route new batches toward under-covered gaps (instead of more of the current theme) when
        // ANY of: the search surface is spent, a gap is already overcovered, or the batch fails to
        // advance diverse structural capability.
        $surfaceSpent = in_array($surfaceSaturation['verdict'], [
            AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED,
            AtlasExternalBrainSurfaceSaturationMeter::VERDICT_ROTATE,
        ], true);
        $hasOvercoveredGaps = $roadmapCoverage['overcovered_gaps'] !== [];
        $lowDiversity = ! (bool) ($impactDiversity['advances_more_than_one_structural_capability'] ?? true);

        $reasons = array_values(array_filter([
            $surfaceSpent ? 'search_surface_spent:'.$surfaceSaturation['verdict'] : null,
            $hasOvercoveredGaps ? 'roadmap_gaps_overcovered' : null,
            $lowDiversity ? 'batch_lacks_structural_diversity' : null,
        ]));

        $payload = [
            'schema' => self::SCHEMA,
            'route_to_gaps' => $surfaceSpent || $hasOvercoveredGaps || $lowDiversity,
            'reasons' => $reasons === [] ? ['no_saturation_or_diversity_concern'] : $reasons,
            'roadmap_coverage' => $roadmapCoverage,
            'surface_saturation' => $surfaceSaturation,
            'backlog_aging' => $backlogAging,
            'impact_diversity' => $impactDiversity,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
}

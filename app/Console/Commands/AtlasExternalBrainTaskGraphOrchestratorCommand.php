<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphCriticalPathPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphDependencyStalenessAuditor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphReleaseGate;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only task-graph orchestration runtime. Composes:
 *   1. {@see AtlasExternalBrainTaskGraphCriticalPathPlanner}          — the highest-leverage path
 *   2. {@see AtlasExternalBrainTaskGraphDependencyStalenessAuditor}   — stale/superseded/broken prerequisites
 *   3. {@see AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector}   — which queued tasks unlock the most work
 *   4. {@see AtlasExternalBrainTaskGraphReleaseGate}                 — blocks off-path low-novelty releases
 *      while unresolved critical-path work remains
 *
 * Convergence: the release gate's critical_path_task_ids defaults to the planner's computed
 * critical_path_task_ids whenever the caller has not explicitly overridden it, so off-path,
 * low-novelty releases stay blocked while real critical-path work is still unresolved.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { critical_path_planner:{tasks}, staleness_auditor:{edges, statuses, completion_outcomes, superseded_targets},
 *     prerequisite_detector:{tasks}, release_gate:{candidates, critical_path_task_ids, backlog_depth, novelty_threshold} }
 * Every section is optional; a missing section runs its stage with empty input.
 */
final class AtlasExternalBrainTaskGraphOrchestratorCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:task-graph-orchestrator
        {--input= : Path to a JSON file with critical_path_planner, staleness_auditor, prerequisite_detector, release_gate sections}';

    /** @var string */
    protected $description = 'Read-only task-graph orchestration runtime (critical path + staleness audit + prerequisite unlock + release gate).';

    public function handle(
        AtlasExternalBrainTaskGraphCriticalPathPlanner $criticalPathPlanner,
        AtlasExternalBrainTaskGraphDependencyStalenessAuditor $stalenessAuditor,
        AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector $prerequisiteDetector,
        AtlasExternalBrainTaskGraphReleaseGate $releaseGate,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $plannerSection = is_array($decoded['critical_path_planner'] ?? null) ? $decoded['critical_path_planner'] : [];
        $auditorSection = is_array($decoded['staleness_auditor'] ?? null) ? $decoded['staleness_auditor'] : [];

        $prereqSection = is_array($decoded['prerequisite_detector'] ?? null) ? $decoded['prerequisite_detector'] : [];
        $prereqTasks = is_array($prereqSection['tasks'] ?? null) ? $prereqSection['tasks'] : [];

        $gateSection = is_array($decoded['release_gate'] ?? null) ? $decoded['release_gate'] : [];

        $plannerResult = $criticalPathPlanner->plan($plannerSection);
        $auditorResult = $stalenessAuditor->audit($auditorSection);
        $prereqResult = $prerequisiteDetector->detect(['tasks' => $prereqTasks]);

        // Convergence: keep the release gate honest about unresolved critical-path work unless the
        // caller explicitly overrides it.
        if (! array_key_exists('critical_path_task_ids', $gateSection)) {
            $gateSection['critical_path_task_ids'] = $plannerResult['critical_path_task_ids'];
        }
        $gateResult = $releaseGate->evaluate($gateSection);

        $payload = [
            'status' => 'ok',
            'critical_path_task_ids' => $plannerResult['critical_path_task_ids'],
            'path_score' => $plannerResult['path_score'],
            'bottleneck_tasks' => $plannerResult['bottleneck_tasks'],
            'parallelizable_branches' => $plannerResult['parallelizable_branches'],
            'next_best_task' => $plannerResult['next_best_task'],
            'worker_feed_risk' => $plannerResult['worker_feed_risk'],
            'stale_edges' => $auditorResult['stale_edges'],
            'broken_dependencies' => $auditorResult['broken_dependencies'],
            'superseded_dependents' => $auditorResult['superseded_dependents'],
            'repair_or_retire_recommendations' => $auditorResult['repair_or_retire_recommendations'],
            'prerequisite_candidates' => $prereqResult['prerequisite_candidates'],
            'blocked_dependents' => $prereqResult['blocked_dependents'],
            'release_allowed' => $gateResult['release_allowed'],
            'blocked_task_ids' => $gateResult['blocked_task_ids'],
            'release_reasons' => $gateResult['release_reasons'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}

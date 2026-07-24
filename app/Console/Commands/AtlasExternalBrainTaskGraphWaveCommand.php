<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainExecutableWaveManifest;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphReleaseGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphRoiScheduler;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only critical-path execution manifest. Composes:
 *   1. {@see AtlasExternalBrainTaskGraphRoiScheduler}              — dependency-ordered ROI waves + critical path
 *   2. {@see AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector} — which queued tasks unlock the most downstream work
 *   3. {@see AtlasExternalBrainExecutableWaveManifest}              — the small, ordered, immediately-executable wave
 *   4. {@see AtlasExternalBrainTaskGraphReleaseGate}                — admits only on-path/gap-covering/blocker-repair candidates
 *
 * so the brain ships dependency-safe waves instead of loose tasks with hidden dead ends or
 * collision-prone execution order.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { roi_scheduler:{tasks, context}, prerequisite_detector:{tasks},
 *     wave_manifest:{tasks, muscle_routing_hints, max_wave_size, low_novelty_threshold},
 *     release_gate:{candidates, critical_path_task_ids, backlog_depth, novelty_threshold} }
 * Every section is optional; a missing section runs its stage with empty input.
 */
final class AtlasExternalBrainTaskGraphWaveCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:task-graph-wave
        {--input= : Path to a JSON file with roi_scheduler, prerequisite_detector, wave_manifest, release_gate sections}';

    /** @var string */
    protected $description = 'Read-only critical-path execution wave manifest (ROI scheduler + prerequisite unlock + wave manifest + release gate).';

    public function handle(
        AtlasExternalBrainTaskGraphRoiScheduler $roiScheduler,
        AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector $prerequisiteDetector,
        AtlasExternalBrainExecutableWaveManifest $waveManifest,
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

        $roiSection = is_array($decoded['roi_scheduler'] ?? null) ? $decoded['roi_scheduler'] : [];
        $roiTasks = is_array($roiSection['tasks'] ?? null) ? $roiSection['tasks'] : [];
        $roiContext = is_array($roiSection['context'] ?? null) ? $roiSection['context'] : [];

        $prereqSection = is_array($decoded['prerequisite_detector'] ?? null) ? $decoded['prerequisite_detector'] : [];
        $prereqTasks = is_array($prereqSection['tasks'] ?? null) ? $prereqSection['tasks'] : [];

        $manifestSection = is_array($decoded['wave_manifest'] ?? null) ? $decoded['wave_manifest'] : [];

        $gateSection = is_array($decoded['release_gate'] ?? null) ? $decoded['release_gate'] : [];

        $roiResult = $roiScheduler->schedule($roiTasks, $roiContext);
        $prereqResult = $prerequisiteDetector->detect(['tasks' => $prereqTasks]);
        $manifestResult = $waveManifest->build($manifestSection);
        $gateResult = $releaseGate->evaluate($gateSection);

        $payload = [
            'status' => 'ok',
            'waves' => $roiResult['waves'],
            'warnings' => $roiResult['warnings'],
            'critical_path' => $roiResult['critical_path'],
            'dependency_dead_end_warnings' => $roiResult['dependency_dead_end_warnings'],
            'prerequisite_candidates' => $prereqResult['prerequisite_candidates'],
            'blocked_dependents' => $prereqResult['blocked_dependents'],
            'wave_id' => $manifestResult['wave_id'],
            'ordered_task_ids' => $manifestResult['ordered_task_ids'],
            'out_of_wave_reasons' => $manifestResult['out_of_wave_reasons'],
            'release_allowed' => $gateResult['release_allowed'],
            'blocked_task_ids' => $gateResult['blocked_task_ids'],
            'release_reasons' => $gateResult['release_reasons'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}

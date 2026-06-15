<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageGapDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: surfaces the refactor CONVERSION ceiling. Scans recent grind results for refactors the
 * mutation-adequacy gate refused to certify because the target's existing test does not kill a
 * relocated decision, and prints the exact (target file, surviving decision, sibling test) tuples.
 *
 * This is the honest answer to "why aren't more refactors merging" — each row is a god-method whose
 * refactor is blocked purely by a missing test assertion, i.e. the input for the auto-characterization
 * test lane (raise coverage -> the refactor re-certifies, never lowering the bar).
 */
final class AtlasLoopCoverageGapsCommand extends Command
{
    protected $signature = 'atlas:loop:coverage-gaps {--hours=24 : how far back to scan grind results} {--limit=200 : max tasks to scan} {--json : canonical JSON output}';

    protected $description = 'Read-only: refactors blocked by missing test coverage (the conversion ceiling), with the exact surviving mutant per target.';

    public function handle(AtlasLoopCoverageGapDetector $detector): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, min(2000, (int) $this->option('limit')));

        $tasks = DB::table('atlas_loop_tasks')
            ->where('status', 'done')
            ->where('updated_at', '>', now()->subHours($hours))
            ->whereNotNull('result')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'target_path', 'result']);

        $gaps = [];
        foreach ($tasks as $task) {
            $result = is_string($task->result) ? (json_decode($task->result, true) ?: []) : (array) ($task->result ?? []);
            foreach ($detector->gapsFromTaskResult($result) as $gap) {
                $key = $gap['target_file'].'|'.$gap['mutation_id'];
                $gaps[$key] = $gap; // dedupe across tasks/attempts
            }
        }
        $gaps = array_values($gaps);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema_version' => 'atlas.loop.coverage_gaps.v1',
                'scanned_hours' => $hours,
                'tasks_scanned' => $tasks->count(),
                'gap_count' => count($gaps),
                'gaps' => $gaps,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Refactors blocked by missing test coverage (last {$hours}h, scanned {$tasks->count()} grind results):");
        if ($gaps === []) {
            $this->line('  none — no refactor was blocked by mutation_survived in the window.');

            return self::SUCCESS;
        }
        $this->table(
            ['target file', 'uncovered decision', 'sibling test to strengthen'],
            array_map(static fn (array $g): array => [
                $g['target_file'],
                $g['decision_operator'],
                $g['sibling_test'] ?? '(sibling test not found by convention)',
            ], $gaps),
        );
        $this->line(count($gaps).' refactor(s) are one characterization test away from certifying.');

        return self::SUCCESS;
    }
}

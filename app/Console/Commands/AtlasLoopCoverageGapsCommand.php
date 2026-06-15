<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageGapDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageGapFeeder;
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
    protected $signature = 'atlas:loop:coverage-gaps {--hours=24 : how far back to scan grind results} {--limit=200 : max tasks to scan} {--json : canonical JSON output} {--feed : enqueue a characterization_test task per gap to the live supervisor (requires the lane flag ON)}';

    protected $description = 'Read-only: refactors blocked by missing test coverage (the conversion ceiling), with the exact surviving mutant per target.';

    public function handle(AtlasLoopCoverageGapDetector $detector, AtlasLoopCoverageGapFeeder $feeder): int
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

        $fed = ['enabled' => false, 'campaign' => null, 'enqueued' => [], 'reason' => null];
        if ($this->option('feed')) {
            $fed = $this->feedGaps($feeder, $gaps);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema_version' => 'atlas.loop.coverage_gaps.v1',
                'scanned_hours' => $hours,
                'tasks_scanned' => $tasks->count(),
                'gap_count' => count($gaps),
                'gaps' => $gaps,
                'feed' => $fed,
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

        if ($this->option('feed')) {
            if (! ($fed['enabled'] ?? false)) {
                $this->warn('  --feed: '.($fed['reason'] ?? 'not fed').'.');
            } else {
                $this->info('  --feed: enqueued '.count($fed['enqueued']).' characterization_test task(s) to campaign '.substr((string) $fed['campaign'], 0, 8).'.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string,mixed>>  $gaps
     * @return array{enabled:bool, campaign:?string, enqueued:list<string>, reason:?string}
     */
    private function feedGaps(AtlasLoopCoverageGapFeeder $feeder, array $gaps): array
    {
        if (! (bool) config('atlas.loop.characterization_test_lane_enabled', false)) {
            return ['enabled' => false, 'campaign' => null, 'enqueued' => [], 'reason' => 'lane flag OFF (atlas.loop.characterization_test_lane_enabled)'];
        }
        if ($gaps === []) {
            return ['enabled' => true, 'campaign' => null, 'enqueued' => [], 'reason' => 'no gaps to feed'];
        }
        // Route to the freshest LIVE supervisor (running, not killed, fresh heartbeat) — never a
        // dead/zombie campaign whose tasks no worker would claim.
        $floor = now()->subSeconds(max(60, (int) config('atlas.ai.loop.fix_forward_live_campaign_freshness_seconds', 1800)));
        $campaignId = AtlasLoopCampaign::query()
            ->where('status', AtlasLoopCampaign::STATUS_RUNNING)
            ->where('kill_switch', false)
            ->where('heartbeat_at', '>=', $floor)
            ->orderByDesc('heartbeat_at')
            ->value('id');
        if (! is_string($campaignId) || $campaignId === '') {
            return ['enabled' => true, 'campaign' => null, 'enqueued' => [], 'reason' => 'no live supervisor to receive the tasks'];
        }

        return ['enabled' => true, 'campaign' => (string) $campaignId, 'enqueued' => $feeder->feed((string) $campaignId, $gaps), 'reason' => null];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopHungGrindDetector;
use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopHungGrindDetector} at the operator surface: takes a process snapshot from
 * {@see AtlasLoopProcessTopologyProbe}, wires a progress oracle, and emits the per-pid verdicts
 * (hung / slow_but_live / healthy with etime_s + last_progress_age_s) as JSON.
 *
 * STRICTLY read-only / observe-only: it NEVER signals a process and NEVER reaps a serving row — the kill/reap
 * policy is a separate packet. The detector itself only marks a pid `hung` when BOTH the etime and the
 * progress-age thresholds are exceeded; an unknown progress age (oracle returns null) is slow_but_live.
 */
final class AtlasLoopHungGrindCommand extends Command
{
    /** Container key for an injected progress oracle: callable(int $pid, ?string $campaignId, string $command): ?int. */
    private const ORACLE_BINDING = 'atlas.loop.hung_grind.progress_oracle';

    protected $signature = 'atlas:loop:hung-grind {--json}';

    protected $description = 'Read-only hung-grind detection: per-pid verdicts over the live process topology (never kills).';

    public function handle(): int
    {
        $snapshot = $this->getLaravel()->make(AtlasLoopProcessTopologyProbe::class)->snapshot();

        $detector = new AtlasLoopHungGrindDetector(
            (int) config('atlas.loop.hung_grind.etime_threshold_s', 900),
            (int) config('atlas.loop.hung_grind.progress_age_threshold_s', 600),
            $this->progressOracle(),
        );
        $verdicts = $detector->detect($snapshot);

        $this->line((string) json_encode(['verdicts' => $verdicts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function progressOracle(): callable
    {
        $app = $this->getLaravel();
        if ($app->bound(self::ORACLE_BINDING)) {
            $bound = $app->make(self::ORACLE_BINDING);
            if (is_callable($bound)) {
                return $bound;
            }
        }

        // ponytail: the serving lease records no OS pid yet, so there is no pid→row heartbeat map to read; the
        // real oracle returns null = "progress unknown", which the detector treats as slow_but_live, NEVER hung
        // (its anti-Goodhart floor). Wire a real heartbeat lookup here once the lease carries the worker pid.
        return static fn (int $pid, ?string $campaignId, string $command): ?int => null;
    }
}

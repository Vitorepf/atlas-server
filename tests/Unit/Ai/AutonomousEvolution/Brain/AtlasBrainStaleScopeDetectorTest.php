<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainStaleScopeDetector;
use Tests\TestCase;

final class AtlasBrainStaleScopeDetectorTest extends TestCase
{
    public function test_buckets_scopes_by_freshness(): void
    {
        $path = sys_get_temp_dir().'/atlas-brain-stale-'.bin2hex(random_bytes(4)).'.ndjson';
        // fresh: scope alpha, newest at now-100. stale: beta, newest at now-7200. silent: gamma, no rows.
        $now = 10_000;
        file_put_contents($path, json_encode(['schema' => 'x', 'scope' => 'alpha', 'cycle_id' => 'a', 'result_kind' => 'note', 'reflection' => 'r', 'signals' => [], 'recorded_at' => $now - 100]).PHP_EOL, FILE_APPEND);
        file_put_contents($path, json_encode(['schema' => 'x', 'scope' => 'beta', 'cycle_id' => 'b', 'result_kind' => 'note', 'reflection' => 'r', 'signals' => [], 'recorded_at' => $now - 7200]).PHP_EOL, FILE_APPEND);

        $stream = new AtlasBrainReflectionStream($path);
        $r = (new AtlasBrainStaleScopeDetector)->detect(['alpha', 'beta', 'gamma'], $stream, 3600, $now);

        self::assertSame(['alpha'], $r['fresh']);
        self::assertSame(['gamma'], $r['silent']);
        self::assertSame(1, count($r['stale']));
        self::assertSame('beta', $r['stale'][0]['scope']);
        self::assertSame(7200, $r['stale'][0]['age_seconds']);
    }

    public function test_detector_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainStaleScopeDetector.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}

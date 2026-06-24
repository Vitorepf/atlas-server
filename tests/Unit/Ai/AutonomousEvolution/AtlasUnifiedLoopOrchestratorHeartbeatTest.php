<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasUnifiedLoopOrchestrator;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasUnifiedLoopOrchestratorHeartbeatTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        parent::tearDown();
    }

    public function test_heartbeat_writes_staleness_keys_into_heartbeat_json(): void
    {
        config(['atlas.loop.unified.heartbeat_stale_seconds' => 321]);

        $orchestrator = $this->orchestrator();
        $runDir = $this->runDir();

        $this->heartbeat($orchestrator, $runDir, 7, ['attempted' => 2, 'stopped' => null]);

        $payload = json_decode((string) file_get_contents($runDir.'/heartbeat.json'), true);

        $this->assertIsArray($payload);
        $this->assertIsInt($payload['at'] ?? null);
        $this->assertSame(7, $payload['cycle'] ?? null);
        $this->assertSame(['attempted' => 2, 'stopped' => null], $payload['last_cycle'] ?? null);
        $this->assertSame(321, $payload['stale_threshold_seconds'] ?? null);
        $this->assertSame(getmypid(), $payload['process_pid'] ?? null);
        $this->assertNotFalse(strtotime((string) ($payload['wall_time_iso'] ?? '')));
    }

    public function test_staleness_is_true_when_heartbeat_is_missing(): void
    {
        config(['atlas.loop.unified.heartbeat_stale_seconds' => 600]);

        $orchestrator = $this->orchestrator();
        $runDir = $this->runDir();

        $status = $orchestrator->staleness($runDir);

        $this->assertTrue($status['stale']);
        $this->assertSame(0, $status['age_seconds']);
        $this->assertSame(600, $status['threshold']);
        $this->assertSame(0, $status['last_cycle']);
        $this->assertSame('heartbeat_missing', $status['reason'] ?? null);
    }

    public function test_staleness_is_true_when_heartbeat_file_is_older_than_threshold(): void
    {
        config(['atlas.loop.unified.heartbeat_stale_seconds' => 60]);

        $orchestrator = $this->orchestrator();
        $runDir = $this->runDir();

        $this->heartbeat($orchestrator, $runDir, 3, ['attempted' => 1]);
        touch($runDir.'/heartbeat.json', time() - 61);

        $status = $orchestrator->staleness($runDir);

        $this->assertTrue($status['stale']);
        $this->assertGreaterThanOrEqual(61, $status['age_seconds']);
        $this->assertSame(60, $status['threshold']);
        $this->assertSame(3, $status['last_cycle']);
        $this->assertSame('heartbeat_stale', $status['reason'] ?? null);
    }

    public function test_staleness_is_false_within_threshold_and_live_marker_mtime_advances(): void
    {
        config(['atlas.loop.unified.heartbeat_stale_seconds' => 60]);

        $orchestrator = $this->orchestrator();
        $runDir = $this->runDir();

        $this->heartbeat($orchestrator, $runDir, 1, ['attempted' => 1]);
        $livePath = $runDir.'/LIVE';
        $firstMtime = filemtime($livePath);

        $this->assertIsInt($firstMtime);

        $this->heartbeat($orchestrator, $runDir, 2, ['attempted' => 2]);
        $secondMtime = filemtime($livePath);

        $this->assertIsInt($secondMtime);
        $this->assertGreaterThan($firstMtime, $secondMtime);

        touch($runDir.'/heartbeat.json', time() - 10);
        $status = $orchestrator->staleness($runDir);

        $this->assertFalse($status['stale']);
        $this->assertGreaterThanOrEqual(10, $status['age_seconds']);
        $this->assertSame(60, $status['threshold']);
        $this->assertSame(2, $status['last_cycle']);
        $this->assertSame('heartbeat_fresh', $status['reason'] ?? null);
    }

    private function orchestrator(): AtlasUnifiedLoopOrchestrator
    {
        return (new ReflectionClass(AtlasUnifiedLoopOrchestrator::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<string,mixed>  $cycleResult
     */
    private function heartbeat(AtlasUnifiedLoopOrchestrator $orchestrator, string $runDir, int $cycle, array $cycleResult): void
    {
        $method = new ReflectionMethod($orchestrator, 'heartbeat');
        $method->setAccessible(true);
        $method->invoke($orchestrator, $runDir, $cycle, $cycleResult);
    }

    private function runDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-unified-heartbeat-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->paths[] = $dir;

        return $dir;
    }
}

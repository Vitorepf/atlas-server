<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasUnifiedLoopSupervisorService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasUnifiedLoopSupervisorServiceTest extends TestCase
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

    public function test_fresh_running_report_with_php_worker_is_healthy(): void
    {
        $runDir = $this->runDir('run-healthy', 'running', time());
        $service = new AtlasUnifiedLoopSupervisorService;
        $service->setProcessRowsForTesting(fn (): array => [
            ['pid' => 123, 'command' => 'php artisan atlas:loop:unified --run-id=run-healthy --max-seconds=86400'],
        ]);

        $report = $service->assess($runDir, ['run_id' => 'run-healthy']);

        $this->assertSame(AtlasUnifiedLoopSupervisorService::STATUS_HEALTHY, $report['status']);
        $this->assertTrue($report['php_worker_alive']);
        $this->assertFalse($report['restart_recommended']);
        $this->assertSame([], $report['blockers']);
    }

    public function test_running_report_without_real_php_worker_recommends_restart(): void
    {
        $runDir = $this->runDir('run-stale', 'running', time() - 3600);
        touch($runDir.'/report.json', time() - 3600);
        $service = new AtlasUnifiedLoopSupervisorService;
        $service->setProcessRowsForTesting(fn (): array => [
            ['pid' => 10, 'command' => 'zsh -c php artisan atlas:loop:unified --run-id=run-stale'],
        ]);

        $report = $service->assess($runDir, [
            'run_id' => 'run-stale',
            'max_heartbeat_age_seconds' => 900,
            'max_report_age_seconds' => 900,
        ]);

        $this->assertSame(AtlasUnifiedLoopSupervisorService::STATUS_STALE_RUNNING, $report['status']);
        $this->assertFalse($report['php_worker_alive']);
        $this->assertTrue($report['restart_recommended']);
        $this->assertContains('running_report_without_php_worker', $report['blockers']);
        $this->assertContains('heartbeat_stale', $report['blockers']);
        $this->assertStringContainsString('--run-id=run-stale', (string) $report['restart_command']);
    }

    public function test_terminal_report_does_not_recommend_restart_when_worker_is_absent(): void
    {
        $runDir = $this->runDir('run-done', 'once', time() - 60);
        $service = new AtlasUnifiedLoopSupervisorService;
        $service->setProcessRowsForTesting(fn (): array => []);

        $report = $service->assess($runDir, ['run_id' => 'run-done']);

        $this->assertSame(AtlasUnifiedLoopSupervisorService::STATUS_TERMINAL, $report['status']);
        $this->assertFalse($report['php_worker_alive']);
        $this->assertFalse($report['restart_recommended']);
    }

    private function runDir(string $runId, string $status, int $heartbeatAt): string
    {
        $dir = sys_get_temp_dir().'/atlas-unified-supervisor-'.bin2hex(random_bytes(4)).'/'.$runId;
        mkdir($dir, 0o755, true);
        $this->paths[] = dirname($dir);

        file_put_contents($dir.'/report.json', json_encode([
            'schema_version' => 'atlas.loop.unified_run.v1.report',
            'run_id' => $runId,
            'status' => $status,
            'merged_to_main' => false,
            'provider' => 'hermes_cli',
            'modes' => ['deadcode', 'docs_structure'],
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/heartbeat.json', json_encode([
            'at' => $heartbeatAt,
            'cycle' => 1,
        ], JSON_UNESCAPED_SLASHES));

        return $dir;
    }
}

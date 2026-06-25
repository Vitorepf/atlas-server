<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService;
use Tests\TestCase;

final class AtlasTaskServingComprehensionOutcomeInvalidationTest extends TestCase
{
    private string $tempRoot = '';

    private AtlasLoopComprehensionCadenceService $cadence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/atlas-outcome-'.bin2hex(random_bytes(6));
        @mkdir($this->tempRoot, 0o755, true);
        $this->cadence = new AtlasLoopComprehensionCadenceService(
            snapshotRoot: $this->tempRoot.'/comp',
            logPath: $this->tempRoot.'/cortex-comprehension-build.log',
            nowIso: fn () => '2026-06-25T05:00:00Z',
        );
        app()->instance(AtlasLoopComprehensionCadenceService::class, $this->cadence);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tempRoot);
        parent::tearDown();
    }

    private function rrm(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $f) {
            $abs = (string) $f;
            is_dir($abs) ? $this->rrm($abs) : @unlink($abs);
        }
        @rmdir($dir);
    }

    public function test_invalidate_removes_existing_snapshot_and_writes_log_entry(): void
    {
        $rebuild = $this->cadence->rebuild();
        $this->assertTrue($rebuild['ok']);
        $this->assertFileExists($rebuild['snapshot_path']);

        $result = $this->cadence->invalidate('task_outcome_give_back:P-1');
        $this->assertTrue($result['ok']);
        $this->assertSame('task_outcome_give_back:P-1', $result['reason']);
        $this->assertFileDoesNotExist($rebuild['snapshot_path']);

        $log = (string) file_get_contents($this->cadence->logPath());
        $this->assertStringContainsString('invalidate', $log);
        $this->assertStringContainsString('task_outcome_give_back:P-1', $log);
    }

    public function test_build_failure_writes_error_to_log_and_returns_envelope_with_error(): void
    {
        // Force a build failure by pointing snapshotRoot at a path under a regular file
        // so mkdir fails.
        $blocker = $this->tempRoot.'/blocker';
        file_put_contents($blocker, 'i am a file not a directory');
        $service = new AtlasLoopComprehensionCadenceService(
            snapshotRoot: $blocker.'/comp',
            logPath: $this->tempRoot.'/cortex-comprehension-build.log',
            nowIso: fn () => '2026-06-25T05:00:00Z',
        );

        $result = $service->rebuild();
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $log = (string) file_get_contents($service->logPath());
        $this->assertStringContainsString('build_failure', $log);
    }

    public function test_no_snapshot_file_lingering_after_invalidate(): void
    {
        $this->cadence->rebuild();
        $path = $this->cadence->snapshotPath();
        $this->assertFileExists($path);

        $this->cadence->invalidate('test');
        $this->assertFileDoesNotExist($path);
        // The parent dir may remain empty, that's fine — we only assert the snapshot itself is gone.
    }
}

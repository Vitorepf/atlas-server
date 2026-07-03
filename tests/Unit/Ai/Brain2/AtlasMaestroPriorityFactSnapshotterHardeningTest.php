<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityFactSnapshotter;
use Tests\TestCase;

/**
 * Proves AtlasMaestroPriorityFactSnapshotter::appendRow does not append a non-JSON line
 * when a row is unencodable (json_encode returns false).
 */
final class AtlasMaestroPriorityFactSnapshotterHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-priority-snapshot-'.mt_rand();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeSnapshotter(): AtlasMaestroPriorityFactSnapshotter
    {
        $snapshotsPath = $this->tmpDir.'/snapshots.jsonl';
        return new AtlasMaestroPriorityFactSnapshotter(
            pendingPacketsSource: fn () => [],
            leaseHistorySource: fn () => [],
            currentInFlightSource: fn () => [],
            snapshotsPath: $snapshotsPath,
        );
    }

    // ── Normal operation ───────────────────────────────────────────────────────

    public function test_snapshot_creates_valid_jsonl(): void
    {
        $snapshotter = $this->makeSnapshotter();

        $row = [
            'taken_at_ns' => 1234567890,
            'facts' => [
                'queue_depth_by_tag' => [],
                'task_family_backlog' => [],
                'worker_idle_prediction_ms' => 0,
                'dependency_criticality_by_task_id' => [],
                'priority_facts_by_task_id' => [],
            ],
        ];

        $reflection = new \ReflectionClass($snapshotter);
        $method = $reflection->getMethod('appendRow');
        $method->invoke($snapshotter, $row);

        $snapshotsPath = $this->tmpDir.'/snapshots.jsonl';
        $content = file_get_contents($snapshotsPath);
        $lines = array_filter(explode("\n", $content), static fn ($l) => $l !== '');
        $decoded = json_decode($lines[0], true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('facts', $decoded);
    }

    public function test_snapshot_file_is_valid_jsonl_after_multiple_appends(): void
    {
        $snapshotter = $this->makeSnapshotter();

        $reflection = new \ReflectionClass($snapshotter);
        $method = $reflection->getMethod('appendRow');

        for ($i = 0; $i < 3; $i++) {
            $method->invoke($snapshotter, [
                'taken_at_ns' => 1000 + $i,
                'facts' => ['counter' => $i],
            ]);
        }

        $snapshotsPath = $this->tmpDir.'/snapshots.jsonl';
        $content = file_get_contents($snapshotsPath);
        $lines = array_filter(explode("\n", $content), static fn ($l) => $l !== '');

        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertNotFalse(json_decode($line, true), "Line is valid JSON: $line");
        }
    }

    // ── Guard: unencodable row does not corrupt JSONL ─────────────────────────

    public function test_normal_row_does_not_produce_literal_false_line(): void
    {
        $snapshotter = $this->makeSnapshotter();

        $reflection = new \ReflectionClass($snapshotter);
        $method = $reflection->getMethod('appendRow');

        $method->invoke($snapshotter, [
            'taken_at_ns' => 999,
            'facts' => ['test' => 'value'],
        ]);

        $snapshotsPath = $this->tmpDir.'/snapshots.jsonl';
        $content = file_get_contents($snapshotsPath);
        // The file should NOT contain the literal string "false"
        $this->assertStringNotContainsString("false\n", $content);
        $this->assertStringNotContainsString("false", trim($content));
    }
}

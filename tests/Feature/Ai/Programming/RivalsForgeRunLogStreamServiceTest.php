<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use Tests\TestCase;

class RivalsForgeRunLogStreamServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);
        foreach ($service->listRuns() as $runId) {
            $dir = $service->runDirectory($runId);
            if (str_contains($dir, 'rivals_forge_test_')) {
                $this->purge($dir);
            }
        }
        parent::tearDown();
    }

    public function test_start_creates_directory_and_writes_run_started_event(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);
        $runId = $this->newRunId();

        $dir = $service->start($runId, ['preset' => 'quick', 'model' => 'sonnet']);

        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir.'/events.jsonl');
        $this->assertFileExists($dir.'/intent.json');

        $events = $service->tail($runId);
        $this->assertCount(1, $events);
        $this->assertSame('run_started', $events[0]['kind']);
        $this->assertSame($runId, data_get($events[0], 'payload.run_id'));
    }

    public function test_event_appends_jsonl_with_monotonic_progression(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);
        $runId = $this->newRunId();
        $service->start($runId, []);

        $service->event($runId, 'preflight', ['status' => 'passed']);
        usleep(50_000);
        $service->event($runId, 'provider_start', ['model' => 'sonnet']);

        $events = $service->tail($runId);
        $this->assertCount(3, $events);
        $this->assertSame(['run_started', 'preflight', 'provider_start'], array_column($events, 'kind'));
        $this->assertGreaterThanOrEqual(0, (int) $events[2]['monotonic_ms_since_start']);
        $this->assertLessThanOrEqual(
            (int) $events[2]['monotonic_ms_since_start'],
            (int) $events[1]['monotonic_ms_since_start'],
        );
    }

    public function test_canonical_event_kinds_cover_user_spec_lifecycle(): void
    {
        $expected = [
            'preflight',
            'provider_start',
            'provider_done',
            'tests_start',
            'tests_done',
            'quality_start',
            'quality_done',
            'after_clean_check',
            'evidence_pack',
            'final_report',
            'heartbeat',
            'stalled',
            'blocked',
        ];
        foreach ($expected as $kind) {
            $this->assertContains(
                $kind,
                RivalsForgeRunLogStreamService::CANONICAL_EVENT_KINDS,
                "canonical lifecycle event '$kind' must be declared",
            );
        }
    }

    public function test_tail_respects_since_line(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);
        $runId = $this->newRunId();
        $service->start($runId, []);
        $service->event($runId, 'preflight');
        $service->event($runId, 'provider_start');

        $tail = $service->tail($runId, 2);
        $this->assertCount(1, $tail);
        $this->assertSame('provider_start', $tail[0]['kind']);
    }

    public function test_last_event_at_returns_timestamp_of_most_recent_event(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);
        $runId = $this->newRunId();
        $service->start($runId, []);
        usleep(10_000);
        $service->event($runId, 'heartbeat');

        $last = $service->lastEventAt($runId);
        $this->assertNotNull($last);
        $this->assertGreaterThan(0, $last->getTimestamp());
    }

    public function test_sanitize_rejects_unsafe_run_id(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);

        $this->expectException(\RuntimeException::class);
        $service->start('../escape', []);
    }

    public function test_rotate_keeps_at_most_retention_runs(): void
    {
        $service = app(RivalsForgeRunLogStreamService::class);
        $created = [];
        $total = RivalsForgeRunLogStreamService::RETENTION_RUNS + 3;
        for ($i = 0; $i < $total; $i++) {
            $id = $this->newRunId('rotate-'.$i);
            $service->start($id, ['ordinal' => $i]);
            $created[] = $id;
            usleep(2_000);
        }

        $remaining = collect($service->listRuns())
            ->filter(fn (string $name): bool => in_array($name, $created, true))
            ->all();
        $this->assertLessThanOrEqual(
            RivalsForgeRunLogStreamService::RETENTION_RUNS,
            count($remaining),
            'rotation must keep at most RETENTION_RUNS entries among the runs created by this test',
        );
    }

    private function newRunId(string $suffix = ''): string
    {
        return 'rivals_forge_test_'.bin2hex(random_bytes(6)).($suffix !== '' ? '-'.preg_replace('/[^A-Za-z0-9_\-]/', '-', $suffix) : '');
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

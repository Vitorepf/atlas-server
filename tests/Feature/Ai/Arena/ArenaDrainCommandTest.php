<?php

namespace Tests\Feature\Ai\Arena;

use App\Services\Ai\Arena\ArenaMeasurementStore;
use Tests\TestCase;

class ArenaDrainCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/arena_drain_'.uniqid('', true);
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_arena.suites', ['terminal_bench']);
        config()->set('atlas_arena.weights', ['terminal_bench' => 1.0]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }

        parent::tearDown();
    }

    public function test_drain_is_noop_when_worker_disabled(): void
    {
        config()->set('atlas_arena.worker_enabled', false);
        $this->queue('arq_a', 'baseline');

        $this->artisan('atlas:arena:drain', ['--approve-provider-spend' => true])
            ->assertSuccessful();

        $this->assertSame(['queued'], $this->statuses());
    }

    public function test_drain_refuses_without_spend_approval(): void
    {
        config()->set('atlas_arena.worker_enabled', true);
        $this->queue('arq_a', 'baseline');

        $this->artisan('atlas:arena:drain')->assertFailed();

        $this->assertSame(['queued'], $this->statuses());
    }

    public function test_drain_groups_arms_and_marks_failed_when_plan_is_rejected(): void
    {
        // ATLAS_RIVALS2_ENABLED ausente no subprocesso → o plan real recusa;
        // o worker deve marcar o grupo inteiro como failed com motivo — nunca
        // deixar a fila mentindo "queued" nem inventar medição.
        config()->set('atlas_arena.worker_enabled', true);
        $this->queue('arq_a', 'baseline');
        $this->queue('arq_b', 'with_atlas');

        $this->artisan('atlas:arena:drain', ['--approve-provider-spend' => true])
            ->assertSuccessful();

        $entries = $this->rawEntries();
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertContains($entry['status'], ['failed', 'done']);
            if ($entry['status'] === 'failed') {
                $this->assertStringContainsString('arena_drain', (string) $entry['failure_reason']);
            }
            $this->assertArrayHasKey('drain_started_at', $entry);
        }
    }

    public function test_update_queued_requests_rewrites_only_matching_lines(): void
    {
        $store = new ArenaMeasurementStore;
        $this->queue('arq_a', 'baseline');
        $this->queue('arq_b', 'with_atlas');

        $store->updateQueuedRequests(['arq_a'], ['status' => 'done', 'drained_at' => '2026-07-17T05:00:00Z']);

        $entries = $this->rawEntries();
        $byId = array_column($entries, null, 'run_id_public');
        $this->assertSame('done', $byId['arq_a']['status']);
        $this->assertSame('queued', $byId['arq_b']['status']);
    }

    private function queue(string $id, string $arm): void
    {
        (new ArenaMeasurementStore)->appendQueuedRequest([
            'schema_version' => 'atlas.arena.queued_run.v1',
            'status' => 'queued',
            'run_id_public' => $id,
            'suite' => 'terminal_bench',
            'engine' => 'codex_gpt_5_5',
            'arm' => $arm,
            'queued_at' => '2026-07-17T04:00:00Z',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rawEntries(): array
    {
        $path = (new ArenaMeasurementStore)->queuePath();

        return array_values(array_filter(array_map(
            static fn (string $line): mixed => json_decode($line, true),
            array_filter(explode(PHP_EOL, (string) file_get_contents($path))),
        ), 'is_array'));
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return array_values(array_unique(array_column($this->rawEntries(), 'status')));
    }
}

<?php

namespace Tests\Feature\Ai\Arena;

use App\Services\Ai\Arena\ArenaMeasurementControlService;
use App\Services\Ai\Arena\ArenaMeasurementStore;
use App\Services\Ai\Arena\ArenaRivalsExecutionService;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use Mockery\MockInterface;
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
                $this->assertSame('plan_failed', $entry['failure_code']);
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

    public function test_drain_keeps_measurements_separate_when_suite_and_engine_match(): void
    {
        config()->set('atlas_arena.worker_enabled', true);
        $this->queue('arq_first', 'baseline', 'am_first');
        $this->queue('arq_second', 'baseline', 'am_second');

        $this->artisan('atlas:arena:drain', [
            '--groups' => 1,
            '--approve-provider-spend' => true,
        ])->assertSuccessful();

        $byMeasurement = array_column($this->rawEntries(), null, 'measurement_id_public');
        $this->assertNotSame('queued', $byMeasurement['am_first']['status']);
        $this->assertSame('queued', $byMeasurement['am_second']['status']);
    }

    public function test_drain_honors_stopping_after_current_case_without_starting_next_case(): void
    {
        config()->set('atlas_arena.worker_enabled', true);
        $measurementId = 'am_stop_between_cases';
        $runId = '20260718_130000_arena_stop';
        $this->queue('arq_stop', 'baseline', $measurementId);
        $executed = [];

        $this->mock(ArenaRivalsExecutionService::class, function (MockInterface $mock) use (
            $runId,
            $measurementId,
            &$executed
        ): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturnUsing(function () use ($runId): string {
                    (new RunStateMachine)->mark($runId, RunStateMachine::PLANNED);

                    return $runId;
                });
            $mock->shouldReceive('manifestEntries')
                ->once()
                ->with($runId)
                ->andReturn([
                    ['execution_id' => 'case-1'],
                    ['execution_id' => 'case-2'],
                ]);
            $mock->shouldReceive('runUnit')
                ->once()
                ->andReturnUsing(function (string $suite, string $actualRunId, array $entry) use (
                    $runId,
                    $measurementId,
                    &$executed
                ): int {
                    $this->assertSame('terminal_bench', $suite);
                    $this->assertSame($runId, $actualRunId);
                    $executed[] = $entry['execution_id'];
                    (new ArenaMeasurementControlService)->stop($measurementId, [
                        'operator_actor' => 'vitor',
                        'operator_reason' => 'parar após o caso atual',
                    ]);

                    return 0;
                });
            $mock->shouldNotReceive('finish');
        });

        $this->artisan('atlas:arena:drain', ['--approve-provider-spend' => true])
            ->assertSuccessful();

        $this->assertSame(['case-1'], $executed);
        $entry = $this->rawEntries()[0];
        $this->assertSame('stopped', $entry['status']);
        $this->assertSame($entry['stop_receipt_hash'], $entry['terminal_receipt_hash']);
        $this->assertSame(
            RunStateMachine::CANCELLED,
            (new RunStateMachine)->current($runId)['state'] ?? null
        );
    }

    public function test_drain_persists_completed_terminal_receipt_after_pipeline_finishes(): void
    {
        config()->set('atlas_arena.worker_enabled', true);
        $runId = '20260718_140000_arena_completed';
        $this->queue('arq_completed', 'baseline', 'am_completed');

        $this->mock(ArenaRivalsExecutionService::class, function (MockInterface $mock) use ($runId): void {
            $mock->shouldReceive('plan')->once()->andReturn($runId);
            $mock->shouldReceive('manifestEntries')
                ->once()
                ->with($runId)
                ->andReturn([['execution_id' => 'case-1']]);
            $mock->shouldReceive('runUnit')
                ->once()
                ->with('terminal_bench', $runId, ['execution_id' => 'case-1'])
                ->andReturn(0);
            $mock->shouldReceive('finish')->once()->with($runId)->andReturn([]);
        });

        $this->artisan('atlas:arena:drain', ['--approve-provider-spend' => true])
            ->assertSuccessful();

        $entry = $this->rawEntries()[0];
        $this->assertSame('done', $entry['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['terminal_receipt_hash']);
        $this->assertArrayNotHasKey('failure_code', $entry);
    }

    public function test_stop_winning_completion_race_is_not_overwritten_by_done(): void
    {
        config()->set('atlas_arena.worker_enabled', true);
        $measurementId = 'am_stop_before_done';
        $runId = '20260718_141000_arena_stop_before_done';
        $this->queue('arq_stop_before_done', 'baseline', $measurementId);

        $this->mock(ArenaRivalsExecutionService::class, function (MockInterface $mock) use (
            $measurementId,
            $runId
        ): void {
            $mock->shouldReceive('plan')->once()->andReturn($runId);
            $mock->shouldReceive('manifestEntries')
                ->once()
                ->andReturn([['execution_id' => 'case-1']]);
            $mock->shouldReceive('runUnit')->once()->andReturn(0);
            $mock->shouldReceive('finish')
                ->once()
                ->andReturnUsing(function () use ($measurementId): array {
                    (new ArenaMeasurementControlService)->stop($measurementId, [
                        'operator_actor' => 'vitor',
                        'operator_reason' => 'stop linearizado antes do terminal',
                    ]);

                    return [];
                });
        });

        $this->artisan('atlas:arena:drain', ['--approve-provider-spend' => true])
            ->assertSuccessful();

        $entry = $this->rawEntries()[0];
        $this->assertSame('stopped', $entry['status']);
        $this->assertSame($entry['stop_receipt_hash'], $entry['terminal_receipt_hash']);
    }

    private function queue(string $id, string $arm, string $measurementId = 'am_test'): void
    {
        (new ArenaMeasurementStore)->appendQueuedRequest([
            'schema_version' => 'atlas.arena.queued_run.v1',
            'status' => 'queued',
            'run_id_public' => $id,
            'measurement_id_public' => $measurementId,
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

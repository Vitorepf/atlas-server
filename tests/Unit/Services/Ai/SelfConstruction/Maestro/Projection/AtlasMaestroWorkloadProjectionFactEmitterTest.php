<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadProjectionFactEmitter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Maestro predicts worker starvation and queue exhaustion from append-only facts instead of
 * guessing: emitFactRow rejects malformed rows without appending, factHistory tails valid JSON
 * rows, emit classifies healthy/low_buffer/replenish_now as time-to-empty crosses the documented
 * thresholds, and per-worker rows are deterministic by client_id.
 */
final class AtlasMaestroWorkloadProjectionFactEmitterTest extends TestCase
{
    private string $logPath;

    private AtlasMaestroWorkloadProjectionFactEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = sys_get_temp_dir().'/atlas-maestro-workload-projection-fact-declared-'.uniqid('', true).'.jsonl';
        $this->emitter = new AtlasMaestroWorkloadProjectionFactEmitter();
        $this->emitter->setFactLogPathForTesting($this->logPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }
        parent::tearDown();
    }

    // ── emitFactRow: reject malformed rows without appending ────────────────────

    public function test_emit_fact_row_rejects_missing_required_fields_without_appending(): void
    {
        $result = $this->emitter->emitFactRow(['claimable_depth' => 5]);

        self::assertFalse($result['accepted']);
        self::assertContains('active_workers', $result['missing_fields']);
        self::assertContains('telemetry_confidence', $result['missing_fields']);
        self::assertNull($result['row']);
        self::assertSame([], $this->emitter->factHistory(), 'a rejected row must not reach the JSONL fact log');
    }

    public function test_emit_fact_row_appends_a_valid_row(): void
    {
        $result = $this->emitter->emitFactRow([
            'claimable_depth' => 12,
            'active_workers' => 4,
            'telemetry_confidence' => 0.9,
        ]);

        self::assertTrue($result['accepted']);
        self::assertSame(12, $result['row']['claimable_depth']);
        self::assertCount(1, $this->emitter->factHistory());
    }

    // ── factHistory: tails valid JSON rows ───────────────────────────────────────

    public function test_fact_history_tails_valid_rows_in_append_order(): void
    {
        $this->emitter->emitFactRow(['claimable_depth' => 5, 'active_workers' => 2, 'telemetry_confidence' => 0.8]);
        $this->emitter->emitFactRow(['claimable_depth' => 10, 'active_workers' => 3, 'telemetry_confidence' => 0.7]);
        $this->emitter->emitFactRow(['claimable_depth' => 15, 'active_workers' => 4, 'telemetry_confidence' => 0.6]);

        $history = $this->emitter->factHistory();

        self::assertCount(3, $history);
        self::assertSame(5, $history[0]['claimable_depth']);
        self::assertSame(15, $history[2]['claimable_depth']);
    }

    // ── emit: risk classification crosses documented thresholds ─────────────────

    public function test_emit_classifies_replenish_now_below_the_replenish_horizon(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        $snapshot = ['packets' => [
            ['task_packet_id' => 'p1', 'status' => 'queued'],
            ['task_packet_id' => 'p2', 'status' => 'queued'],
        ]];

        // 2 queued / 10 tasks_per_hour = 0.2h < HORIZON_REPLENISH_HOURS(1.0)
        $fact = $this->emitter->emit($this->consumptionFact(10.0, [], $now), $snapshot, $now);

        self::assertSame(AtlasMaestroWorkloadProjectionFactEmitter::RISK_REPLENISH_NOW, $fact['risk']);
    }

    public function test_emit_classifies_low_buffer_between_thresholds(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        // 5 queued / 2 tasks_per_hour = 2.5h -> between 1.0 and 4.0 -> low_buffer
        $packets = array_map(
            static fn (int $i): array => ['task_packet_id' => "p{$i}", 'status' => 'queued'],
            range(1, 5),
        );

        $fact = $this->emitter->emit($this->consumptionFact(2.0, [], $now), ['packets' => $packets], $now);

        self::assertSame(AtlasMaestroWorkloadProjectionFactEmitter::RISK_LOW_BUFFER, $fact['risk']);
    }

    public function test_emit_classifies_healthy_above_the_low_buffer_horizon(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        // 20 queued / 1 task_per_hour = 20h > HORIZON_LOW_BUFFER_HOURS(4.0) -> healthy
        $packets = array_map(
            static fn (int $i): array => ['task_packet_id' => "p{$i}", 'status' => 'queued'],
            range(1, 20),
        );

        $fact = $this->emitter->emit($this->consumptionFact(1.0, [], $now), ['packets' => $packets], $now);

        self::assertSame(AtlasMaestroWorkloadProjectionFactEmitter::RISK_HEALTHY, $fact['risk']);
    }

    public function test_emit_marks_insufficient_throughput_on_zero_fleet_rate(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');

        $fact = $this->emitter->emit($this->consumptionFact(0.0, [], $now), ['packets' => [
            ['task_packet_id' => 'p1', 'status' => 'queued'],
        ]], $now);

        self::assertNull($fact['queue_empty_in_hours']);
        self::assertSame('insufficient_throughput', $fact['reason']);
    }

    // ── emit: per-worker rows deterministic by client_id ─────────────────────────

    public function test_per_worker_rows_are_sorted_deterministically_by_client_id(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        $snapshot = ['packets' => [
            ['task_packet_id' => 'p1', 'status' => 'claimable', 'client_id' => 'worker-b'],
            ['task_packet_id' => 'p2', 'status' => 'claimable', 'client_id' => 'worker-a'],
        ]];

        $fact = $this->emitter->emit(
            $this->consumptionFact(10.0, [
                ['client_id' => 'worker-b', 'tasks_per_hour' => 4.0],
                ['client_id' => 'worker-a', 'tasks_per_hour' => 6.0],
            ], $now),
            $snapshot,
            $now,
        );

        self::assertSame('worker-a', $fact['per_worker'][0]['client_id']);
        self::assertSame('worker-b', $fact['per_worker'][1]['client_id']);
    }

    /**
     * @param  list<array{client_id:string,tasks_per_hour:float}>  $workers
     * @return array<string,mixed>
     */
    private function consumptionFact(float $fleetRate, array $workers, CarbonImmutable $now): array
    {
        $rows = [];
        foreach ($workers as $worker) {
            $rows[] = ['client_id' => $worker['client_id'], 'tasks_per_hour' => $worker['tasks_per_hour']];
        }
        $rows[] = ['client_id' => 'fleet', 'tasks_per_hour' => $fleetRate];

        return [
            'schema' => 'atlas.maestro.projection.consumption_rate.v1',
            'observed_at_iso' => $now->toIso8601String(),
            'rows' => $rows,
        ];
    }
}

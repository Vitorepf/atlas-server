<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyDigestEmitter;
use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyReceiptLedger;
use Tests\TestCase;

class AtlasLoopAnomalyDigestEmitterTest extends TestCase
{
    private function fact(array $override = []): array
    {
        return array_replace([
            'signal' => 'merge_failure_rate',
            'baseline_rate' => 0.05,
            'current_rate' => 0.20,
            'delta_in_sigmas' => 3.0,
            'window_start' => '2026-06-25T00:00:00Z',
            'window_end' => '2026-06-25T01:00:00Z',
            'observed_at' => '2026-06-25T01:00:00Z',
        ], $override);
    }

    public function test_emit_orders_entries_by_signal_and_returns_most_recent_per_signal(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger();
        $ledger->append($this->fact(['signal' => 'merge_failure_rate', 'observed_at' => '2026-06-25T00:30:00Z', 'current_rate' => 0.10]));
        $ledger->append($this->fact(['signal' => 'merge_failure_rate', 'observed_at' => '2026-06-25T00:45:00Z', 'current_rate' => 0.20]));
        $ledger->append($this->fact(['signal' => 'give_back_rate', 'observed_at' => '2026-06-25T00:50:00Z', 'current_rate' => 0.15]));

        $emitter = new AtlasLoopAnomalyDigestEmitter($ledger, ['merge_failure_rate', 'give_back_rate']);
        $payload = $emitter->emit(['from' => '2026-06-25T00:00:00Z', 'to' => '2026-06-25T02:00:00Z']);

        self::assertSame(AtlasLoopAnomalyDigestEmitter::SECTION, $payload['section']);
        $signals = array_column($payload['entries'], 'signal');
        self::assertSame(['give_back_rate', 'merge_failure_rate'], $signals, 'entries must be ordered by signal ASC');
        // Most recent merge_failure_rate fact has current_rate=0.20.
        $merge = $payload['entries'][array_search('merge_failure_rate', $signals, true)];
        self::assertSame(0.20, $merge['current_rate']);
    }

    public function test_emit_is_byte_identical_for_identical_ledger_and_window(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger();
        $ledger->append($this->fact(['signal' => 'queue_jam_rate', 'observed_at' => '2026-06-25T00:30:00Z']));

        $emitter = new AtlasLoopAnomalyDigestEmitter($ledger, ['queue_jam_rate']);
        $a = $emitter->emitJson(['from' => '2026-06-25T00:00:00Z', 'to' => '2026-06-25T02:00:00Z']);
        $b = $emitter->emitJson(['from' => '2026-06-25T00:00:00Z', 'to' => '2026-06-25T02:00:00Z']);

        self::assertSame($a, $b);
    }

    public function test_emit_returns_empty_entries_section_for_empty_window_without_throwing(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger();
        $emitter = new AtlasLoopAnomalyDigestEmitter($ledger, ['merge_failure_rate']);

        $payload = $emitter->emit(['from' => '2026-06-25T00:00:00Z', 'to' => '2026-06-25T01:00:00Z']);

        self::assertSame(AtlasLoopAnomalyDigestEmitter::SECTION, $payload['section']);
        self::assertSame([], $payload['entries']);
    }

    public function test_signals_without_facts_in_window_are_skipped(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger();
        $ledger->append($this->fact(['signal' => 'merge_failure_rate', 'observed_at' => '2026-06-25T00:30:00Z']));

        $emitter = new AtlasLoopAnomalyDigestEmitter($ledger, ['merge_failure_rate', 'never_seen_signal']);
        $payload = $emitter->emit(['from' => '2026-06-25T00:00:00Z', 'to' => '2026-06-25T02:00:00Z']);

        self::assertCount(1, $payload['entries']);
        self::assertSame('merge_failure_rate', $payload['entries'][0]['signal']);
    }

    public function test_emit_includes_required_fact_fields(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger();
        $ledger->append($this->fact());

        $emitter = new AtlasLoopAnomalyDigestEmitter($ledger, ['merge_failure_rate']);
        $payload = $emitter->emit(['from' => '2026-06-25T00:00:00Z', 'to' => '2026-06-25T02:00:00Z']);
        $entry = $payload['entries'][0];

        foreach (['signal', 'baseline_rate', 'current_rate', 'delta_in_sigmas', 'window_start', 'window_end'] as $field) {
            self::assertArrayHasKey($field, $entry);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyReceiptLedger;
use Tests\TestCase;

final class AtlasLoopAnomalyReceiptLedgerTest extends TestCase
{
    private function fact(array $overrides = []): array
    {
        return array_replace([
            'signal' => 'give_back_rate',
            'baseline_rate' => 0.10,
            'current_rate' => 0.32,
            'delta_in_sigmas' => 4.1,
            'window_start' => '2026-06-25T05:00:00+00:00',
            'window_end' => '2026-06-25T06:00:00+00:00',
            'observed_at' => '2026-06-25T05:55:00+00:00',
        ], $overrides);
    }

    public function test_append_returns_normalized_fact_with_receipt_hash(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger;
        $stored = $ledger->append($this->fact());

        $this->assertSame(AtlasLoopAnomalyReceiptLedger::SCHEMA, $stored['schema_version']);
        $this->assertSame('give_back_rate', $stored['signal']);
        $this->assertSame(0.10, $stored['baseline_rate']);
        $this->assertNotEmpty($stored['receipt_hash']);
        $this->assertSame(1, $ledger->size());
    }

    public function test_history_filters_by_signal_and_window(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger;
        $ledger->append($this->fact(['signal' => 'give_back_rate', 'observed_at' => '2026-06-25T05:00:00+00:00']));
        $ledger->append($this->fact(['signal' => 'give_back_rate', 'observed_at' => '2026-06-25T06:00:00+00:00']));
        $ledger->append($this->fact(['signal' => 'failure_rate', 'observed_at' => '2026-06-25T05:30:00+00:00']));

        $rows = $ledger->history('give_back_rate', '2026-06-25T05:30:00+00:00', '2026-06-25T07:00:00+00:00');

        $this->assertCount(1, $rows);
        $this->assertSame('give_back_rate', $rows[0]['signal']);
        $this->assertSame('2026-06-25T06:00:00+00:00', $rows[0]['observed_at']);
    }

    public function test_history_returns_empty_array_when_no_match(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger;
        $this->assertSame([], $ledger->history('unknown'));

        $ledger->append($this->fact());
        $this->assertSame([], $ledger->history('different_signal'));
    }

    public function test_history_ordering_is_deterministic_by_observed_at(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger;
        $ledger->append($this->fact(['observed_at' => '2026-06-25T05:30:00+00:00']));
        $ledger->append($this->fact(['observed_at' => '2026-06-25T05:00:00+00:00']));
        $ledger->append($this->fact(['observed_at' => '2026-06-25T05:45:00+00:00']));

        $rows = $ledger->history('give_back_rate');
        $observed = array_column($rows, 'observed_at');

        $this->assertSame([
            '2026-06-25T05:00:00+00:00',
            '2026-06-25T05:30:00+00:00',
            '2026-06-25T05:45:00+00:00',
        ], $observed);
    }

    public function test_append_rejects_malformed_facts(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger;

        foreach (['signal', 'baseline_rate', 'current_rate', 'delta_in_sigmas', 'window_start', 'window_end', 'observed_at'] as $missing) {
            $fact = $this->fact();
            unset($fact[$missing]);
            try {
                $ledger->append($fact);
                $this->fail("missing {$missing} should have thrown");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('missing_required_anomaly_fact_key:', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $ledger->append($this->fact(['baseline_rate' => 'not-a-number']));
    }

    public function test_receipt_hash_is_byte_stable_for_identical_facts(): void
    {
        $a = (new AtlasLoopAnomalyReceiptLedger)->append($this->fact());
        $b = (new AtlasLoopAnomalyReceiptLedger)->append($this->fact());

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_window_end_must_not_precede_window_start(): void
    {
        $ledger = new AtlasLoopAnomalyReceiptLedger;
        $this->expectExceptionMessage('window_end_must_not_precede_window_start');
        $ledger->append($this->fact(['window_start' => '2026-06-25T06:00:00+00:00', 'window_end' => '2026-06-25T05:00:00+00:00']));
    }
}

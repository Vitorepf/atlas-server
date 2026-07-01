<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomePatternMiner;
use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomeShapeLedger;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroOutcomePatternMinerTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        $this->ledgerPath = sys_get_temp_dir().'/atlas-outcome-pattern-miner-test-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeRows(array $rows): void
    {
        $lines = array_map(static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR), $rows);
        file_put_contents($this->ledgerPath, implode(PHP_EOL, $lines).PHP_EOL);
    }

    private function miner(): AtlasMaestroOutcomePatternMiner
    {
        return new AtlasMaestroOutcomePatternMiner(new AtlasMaestroOutcomeShapeLedger($this->ledgerPath));
    }

    private function row(string $outcome, array $overrides = []): array
    {
        return array_merge([
            'outcome' => $outcome,
            'origin_kind' => 'orphan',
            'allowed_files_count' => 2,
            'acceptance_criteria_count' => 2,
            'has_tests_path' => true,
            'file_family' => 'unknown',
            'task_shape' => 'unknown',
            'worker_id' => 'unknown',
            'proof_command_class' => 'unknown',
        ], $overrides);
    }

    // ── AC2/AC3/AC4: positive pattern ─────────────────────────────────────────

    public function test_positive_pattern_emits_signal_with_required_fields(): void
    {
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('delivered', ['worker_id' => 'workerA']);
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = $this->row('give_back', ['worker_id' => 'workerA']);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();
        $match = array_values(array_filter($signals, static fn (array $s): bool =>
            $s['polarity'] === 'positive' && $s['dimension'] === 'worker_id' && $s['bucket'] === 'workerA'));

        $this->assertNotEmpty($match, 'expected a positive signal for worker_id=workerA');
        $signal = $match[0];
        foreach (['polarity', 'dimension', 'bucket', 'affected_policy', 'confidence', 'sample_size', 'next_action'] as $k) {
            $this->assertArrayHasKey($k, $signal, "Missing key: {$k}");
        }
        $this->assertSame('routing_policy', $signal['affected_policy']);
        $this->assertEqualsWithDelta(0.75, $signal['confidence'], 0.0001);
        $this->assertSame(8, $signal['sample_size']);
        $this->assertSame('prefer_workerA_for_worker_id_in_routing_policy', $signal['next_action']);
    }

    // ── AC2/AC4: negative pattern (give_back/rejected/stale dominate) ─────────

    public function test_negative_pattern_emits_signal_with_required_fields(): void
    {
        $rows = [];
        for ($i = 0; $i < 2; $i++) {
            $rows[] = $this->row('delivered', ['file_family' => 'php_service']);
        }
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('give_back', ['file_family' => 'php_service']);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();
        $match = array_values(array_filter($signals, static fn (array $s): bool =>
            $s['polarity'] === 'negative' && $s['dimension'] === 'file_family' && $s['bucket'] === 'php_service'));

        $this->assertNotEmpty($match, 'expected a negative signal for file_family=php_service');
        $signal = $match[0];
        $this->assertSame('respec_policy', $signal['affected_policy']);
        $this->assertEqualsWithDelta(0.75, $signal['confidence'], 0.0001);
        $this->assertSame(8, $signal['sample_size']);
        $this->assertSame('avoid_php_service_for_file_family_in_respec_policy', $signal['next_action']);
    }

    // ── AC4: insufficient sample produces no signal ───────────────────────────

    public function test_insufficient_sample_produces_no_signal(): void
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = $this->row('delivered', ['task_shape' => 'rare_shape']);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();
        $match = array_filter($signals, static fn (array $s): bool => $s['bucket'] === 'rare_shape');

        $this->assertEmpty($match, 'a bucket below MIN_SUPPORT must never produce a signal');
    }

    // ── AC4: mixed outcomes produce no signal ─────────────────────────────────

    public function test_mixed_outcomes_produce_no_signal(): void
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = $this->row('delivered', ['proof_command_class' => 'mixed_cmd']);
        }
        for ($i = 0; $i < 4; $i++) {
            $rows[] = $this->row('give_back', ['proof_command_class' => 'mixed_cmd']);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();
        $match = array_filter($signals, static fn (array $s): bool => $s['bucket'] === 'mixed_cmd');

        $this->assertEmpty($match, 'a 50/50 mixed bucket below the delivered floor must never produce a signal');
    }

    // ── AC4: deterministic ordering ───────────────────────────────────────────

    public function test_signals_are_deterministically_ordered(): void
    {
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('delivered', ['worker_id' => 'workerA']);
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = $this->row('give_back', ['worker_id' => 'workerA']);
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = $this->row('delivered', ['file_family' => 'php_service']);
        }
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('give_back', ['file_family' => 'php_service']);
        }
        $this->writeRows($rows);

        $a = $this->miner()->strategyPolicySignals();
        $b = $this->miner()->strategyPolicySignals();

        $this->assertSame(json_encode($a), json_encode($b));

        $positiveIndex = null;
        $negativeIndex = null;
        foreach ($a as $i => $signal) {
            if ($positiveIndex === null && $signal['polarity'] === 'positive') {
                $positiveIndex = $i;
            }
            if ($negativeIndex === null && $signal['polarity'] === 'negative') {
                $negativeIndex = $i;
            }
        }
        $this->assertNotNull($positiveIndex);
        $this->assertNotNull($negativeIndex);
        $this->assertLessThan($negativeIndex, $positiveIndex, 'positive signals must sort before negative signals');
    }

    // ── recency-aware decay ───────────────────────────────────────────────────

    private function daysAgoIso(int $days): string
    {
        return date('c', time() - ($days * 86400));
    }

    public function test_evidence_window_reports_recent_and_stale_counts(): void
    {
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('delivered', ['worker_id' => 'workerA', 'occurred_at' => $this->daysAgoIso(1)]);
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = $this->row('give_back', ['worker_id' => 'workerA', 'occurred_at' => $this->daysAgoIso(90)]);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();
        $match = array_values(array_filter($signals, static fn (array $s): bool =>
            $s['polarity'] === 'positive' && $s['dimension'] === 'worker_id' && $s['bucket'] === 'workerA'));

        $this->assertNotEmpty($match);
        $signal = $match[0];
        $this->assertArrayHasKey('evidence_window', $signal);
        $this->assertSame(6, $signal['evidence_window']['recent_count']);
        $this->assertSame(2, $signal['evidence_window']['stale_count']);
        $this->assertArrayHasKey('recency_weighted_confidence', $signal['evidence_window']);
    }

    public function test_recent_rows_weigh_more_than_old_rows_in_confidence(): void
    {
        // All-recent bucket: 6 delivered / 8 total, all full weight → confidence 0.75.
        $recentRows = [];
        for ($i = 0; $i < 6; $i++) {
            $recentRows[] = $this->row('delivered', ['worker_id' => 'workerRecent', 'occurred_at' => $this->daysAgoIso(1)]);
        }
        for ($i = 0; $i < 2; $i++) {
            $recentRows[] = $this->row('give_back', ['worker_id' => 'workerRecent', 'occurred_at' => $this->daysAgoIso(1)]);
        }

        // Same raw shape, but the give_back rows are stale (decayed weight) → confidence rises above 0.75.
        $mixedRows = [];
        for ($i = 0; $i < 6; $i++) {
            $mixedRows[] = $this->row('delivered', ['worker_id' => 'workerMixed', 'occurred_at' => $this->daysAgoIso(1)]);
        }
        for ($i = 0; $i < 2; $i++) {
            $mixedRows[] = $this->row('give_back', ['worker_id' => 'workerMixed', 'occurred_at' => $this->daysAgoIso(90)]);
        }

        $this->writeRows(array_merge($recentRows, $mixedRows));

        $signals = $this->miner()->strategyPolicySignals();
        $recentSignal = array_values(array_filter($signals, static fn (array $s): bool =>
            $s['dimension'] === 'worker_id' && $s['bucket'] === 'workerRecent'))[0];
        $mixedSignal = array_values(array_filter($signals, static fn (array $s): bool =>
            $s['dimension'] === 'worker_id' && $s['bucket'] === 'workerMixed'))[0];

        $this->assertGreaterThan(
            $recentSignal['confidence'],
            $mixedSignal['confidence'],
            'decayed old give_back rows must weigh less, raising confidence above the all-recent baseline',
        );
    }

    public function test_recent_delivered_evidence_overturns_old_negative_pattern(): void
    {
        // Old, stale give_back trend that would dominate on raw counts alone.
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('give_back', ['file_family' => 'flaky_service', 'occurred_at' => $this->daysAgoIso(90)]);
        }
        // Recent delivered evidence with enough support to contradict it.
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->row('delivered', ['file_family' => 'flaky_service', 'occurred_at' => $this->daysAgoIso(1)]);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();

        $negativeMatch = array_filter($signals, static fn (array $s): bool =>
            $s['polarity'] === 'negative' && $s['bucket'] === 'flaky_service');
        $positiveMatch = array_filter($signals, static fn (array $s): bool =>
            $s['polarity'] === 'positive' && $s['bucket'] === 'flaky_service');

        $this->assertEmpty($negativeMatch, 'recent delivered evidence must overturn the stale negative pattern');
        $this->assertNotEmpty($positiveMatch, 'recency-weighted evidence should now read as a positive pattern');
    }

    public function test_old_negative_pattern_still_holds_without_recent_contradicting_evidence(): void
    {
        $rows = [];
        for ($i = 0; $i < 2; $i++) {
            $rows[] = $this->row('delivered', ['file_family' => 'still_bad', 'occurred_at' => $this->daysAgoIso(90)]);
        }
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('give_back', ['file_family' => 'still_bad', 'occurred_at' => $this->daysAgoIso(90)]);
        }
        $this->writeRows($rows);

        $signals = $this->miner()->strategyPolicySignals();
        $negativeMatch = array_values(array_filter($signals, static fn (array $s): bool =>
            $s['polarity'] === 'negative' && $s['bucket'] === 'still_bad'));

        $this->assertNotEmpty($negativeMatch, 'a negative pattern with no recent contradiction must still hold');
    }
}

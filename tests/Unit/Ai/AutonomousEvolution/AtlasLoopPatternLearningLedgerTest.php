<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternLearningLedger;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the outcome ledger is deterministic, fail-closed and provider-safe.
 */
final class AtlasLoopPatternLearningLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-loop-ledger-test-'.getmypid().'.jsonl';
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_records_and_reads_back_an_outcome(): void
    {
        $ledger = new AtlasLoopPatternLearningLedger($this->path);

        $row = $ledger->record([
            'pattern_id' => 'ticket_to_pr_ready',
            'pattern_version' => '1.0.0',
            'objective_class' => 'refactor',
            'result' => 'success',
            'gates_passed' => ['red_to_green'],
            'gates_failed' => [],
            'measured_outcome' => 0.82,
        ], at: 1_700_000_000);

        $this->assertSame(1_700_000_000, $row['recorded_at']);
        $all = $ledger->all();
        $this->assertCount(1, $all);
        $this->assertSame('ticket_to_pr_ready', $all[0]['pattern_id']);
        $this->assertSame(0.82, $all[0]['measured_outcome']);
    }

    public function test_unattributable_outcome_fails_closed(): void
    {
        $ledger = new AtlasLoopPatternLearningLedger($this->path);

        $this->expectException(InvalidArgumentException::class);
        $ledger->record(['result' => 'success']); // no pattern_id/version/objective_class
    }

    public function test_unknown_result_fails_closed(): void
    {
        $ledger = new AtlasLoopPatternLearningLedger($this->path);

        $this->expectException(InvalidArgumentException::class);
        $ledger->record([
            'pattern_id' => 'p', 'pattern_version' => '1', 'objective_class' => 'docs', 'result' => 'kinda_worked',
        ]);
    }

    public function test_provider_safe_unknown_keys_are_dropped(): void
    {
        $ledger = new AtlasLoopPatternLearningLedger($this->path);

        $row = $ledger->record([
            'pattern_id' => 'p', 'pattern_version' => '1', 'objective_class' => 'docs', 'result' => 'success',
            'raw_prompt' => 'SECRET INTERNAL PROMPT', 'api_key' => 'sk-xxx', 'diff' => 'huge',
        ], at: 1);

        $this->assertArrayNotHasKey('raw_prompt', $row);
        $this->assertArrayNotHasKey('api_key', $row);
        $this->assertArrayNotHasKey('diff', $row);
        // and nothing leaked to disk either
        $this->assertStringNotContainsString('SECRET', (string) file_get_contents($this->path));
    }

    public function test_stats_aggregate_success_rate_and_mean(): void
    {
        $ledger = new AtlasLoopPatternLearningLedger($this->path);
        $base = ['pattern_id' => 'self_improving_champion', 'pattern_version' => '1.0.0', 'objective_class' => 'self_improvement'];

        $ledger->record($base + ['result' => 'success', 'measured_outcome' => 0.9], at: 1);
        $ledger->record($base + ['result' => 'success', 'measured_outcome' => 0.7], at: 2);
        $ledger->record($base + ['result' => 'blocked', 'measured_outcome' => 0.0], at: 3);

        $stats = $ledger->stats('self_improving_champion');
        $this->assertSame(3, $stats['runs']);
        $this->assertSame(2, $stats['successes']);
        $this->assertEqualsWithDelta(2 / 3, $stats['success_rate'], 0.0001);
        $this->assertEqualsWithDelta((0.9 + 0.7 + 0.0) / 3, $stats['mean_outcome'], 0.0001);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\RetrievalFanoutGate;
use PHPUnit\Framework\TestCase;

final class RetrievalFanoutGateTest extends TestCase
{
    public function test_max_run_one_selects_only_highest_scoring_dimension_and_marks_others_skipped_by_budget(): void
    {
        $result = (new RetrievalFanoutGate)->gate(
            ['memory' => 0.6, 'code' => 0.9, 'docs' => 0.7],
            0.5,
            1,
        );

        $this->assertSame(['code'], $result['run']);
        $this->assertContains('memory', $result['skipped']);
        $this->assertContains('docs', $result['skipped']);
        $this->assertSame('skipped_by_budget', $result['reasons']['memory']);
        $this->assertSame('skipped_by_budget', $result['reasons']['docs']);
    }

    public function test_max_run_below_one_is_clamped_so_at_least_one_retriever_runs(): void
    {
        $result = (new RetrievalFanoutGate)->gate(
            ['memory' => 0.9, 'code' => 0.9, 'docs' => 0.9],
            0.5,
            0,
        );

        $this->assertCount(1, $result['run']);

        $resultNegative = (new RetrievalFanoutGate)->gate(
            ['memory' => 0.9, 'code' => 0.9, 'docs' => 0.9],
            0.5,
            -5,
        );
        $this->assertCount(1, $resultNegative['run']);
    }

    public function test_ties_remain_deterministic_in_canonical_dimension_order(): void
    {
        $result = (new RetrievalFanoutGate)->gate(
            ['memory' => 0.8, 'code' => 0.8, 'docs' => 0.8],
            0.5,
            2,
        );

        $this->assertSame(['memory', 'code'], $result['run']);
        $this->assertSame(['docs'], $result['skipped']);
    }

    public function test_max_run_null_is_no_budget_and_preserves_existing_behavior(): void
    {
        $result = (new RetrievalFanoutGate)->gate(['memory' => 0.9, 'code' => 0.9, 'docs' => 0.9], 0.5);

        $this->assertSame(['memory', 'code', 'docs'], $result['run']);
        $this->assertSame([], $result['skipped']);
    }
}

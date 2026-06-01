<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Decision\GateDecisionReducer;
use PHPUnit\Framework\TestCase;

final class GateDecisionReducerTest extends TestCase
{
    public function test_block_dominates_warn_regardless_of_position(): void
    {
        $reducer = new GateDecisionReducer();

        $this->assertSame('warn', $reducer->decide(['pass', 'warn', 'pass']));
        $this->assertSame('block', $reducer->decide(['pass', 'block', 'warn']));
    }

    public function test_empty_fails_closed_and_all_pass_is_the_only_allow_path(): void
    {
        $reducer = new GateDecisionReducer();

        $this->assertSame('block', $reducer->decide([]));
        $this->assertSame('allow', $reducer->decide(['pass', 'pass']));
    }

    public function test_unknown_non_string_and_wrong_case_tokens_fail_closed_to_block(): void
    {
        $reducer = new GateDecisionReducer();

        $this->assertSame('block', $reducer->decide(['pass', 'queued']));
        $this->assertSame('block', $reducer->decide(['warn', null]));
        $this->assertSame('block', $reducer->decide(['PASS']));
    }

    public function test_reduction_is_commutative(): void
    {
        $reducer = new GateDecisionReducer();

        $this->assertSame(
            $reducer->decide(['pass', 'warn', 'block']),
            $reducer->decide(['block', 'warn', 'pass']),
        );
    }

    public function test_warn_floor_is_enforced_for_only_warn_and_pass(): void
    {
        $reducer = new GateDecisionReducer();

        $this->assertSame('warn', $reducer->decide(['warn', 'pass', 'pass', 'warn']));
        $this->assertNotSame('allow', $reducer->decide(['pass', 'warn', 'pass']));
    }
}

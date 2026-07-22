<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Compounding\AtlasCoRecallCompositionDetector;
use Tests\TestCase;

/**
 * MAXJ-07 — co-recall composition floor (8 ⇒ proposal; 2 ⇒ nothing).
 */
final class Maxj07CoRecallCompositionTest extends TestCase
{
    public function test_eight_passing_co_cases_yield_one_composed_candidate(): void
    {
        $cases = [];
        for ($i = 1; $i <= 8; $i++) {
            $cases[] = [
                'session_key' => 'sess-'.$i,
                'memory_a' => 'mem-a',
                'memory_b' => 'mem-b',
                'outcome_ref' => 'rag_feedback:'.$i,
            ];
        }

        $report = (new AtlasCoRecallCompositionDetector)->detectFromCases($cases);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(1, $report['totals']['proposals']);
        $this->assertSame(8, $report['proposals'][0]['co_case_count']);
        $this->assertFalse($report['claim_policy']['auto_promotion_allowed']);
    }

    public function test_two_co_cases_below_floor_yield_nothing(): void
    {
        $cases = [
            ['session_key' => 's1', 'memory_a' => 'a', 'memory_b' => 'b', 'outcome_ref' => 'r1'],
            ['session_key' => 's2', 'memory_a' => 'a', 'memory_b' => 'b', 'outcome_ref' => 'r2'],
        ];

        $report = (new AtlasCoRecallCompositionDetector)->detectFromCases($cases, 8);

        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame(0, $report['totals']['proposals']);
    }

    public function test_enqueue_disabled_does_not_create_rows(): void
    {
        config(['atlas.ai.co_recall_composition.enqueue_enabled' => false]);

        $result = (new AtlasCoRecallCompositionDetector)->enqueueHeld([
            'proposal_hash' => 'abc',
            'claim' => 'x',
            'memory_a_id' => 'a',
            'memory_b_id' => 'b',
            'co_case_count' => 8,
            'outcome_refs' => [],
        ]);

        $this->assertFalse($result['created']);
        $this->assertSame('enqueue_disabled', $result['reason']);
    }
}

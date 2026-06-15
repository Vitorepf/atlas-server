<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeConsensusGate;
use PHPUnit\Framework\TestCase;

/**
 * Next-lever 3 — independent multi-judge consensus. Pure trust arithmetic: independence (distinct
 * engines), lens coverage (every required lens has a passing judge), and quorum. Any dissent => no
 * consensus, with the precise reason the escalation ladder re-rounds on.
 */
final class AtlasLoopJudgeConsensusGateTest extends TestCase
{
    public function test_unanimous_independent_panel_reaches_consensus(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'hermes_cli', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'minimax_m27', 'passes' => true],
            ['lens' => 'security', 'provider' => 'codex', 'passes' => true],
        ], ['policy' => 'unanimous', 'required_lenses' => ['correctness', 'completeness'], 'min_distinct_providers' => 3]);

        $this->assertTrue($r['consensus']);
        $this->assertSame(3, $r['distinct_providers']);
        $this->assertNull($r['reason']);
    }

    public function test_a_single_dissent_blocks_consensus(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'hermes_cli', 'passes' => true],
            ['lens' => 'security', 'provider' => 'minimax_m27', 'passes' => false, 'reason' => 'sqli_risk'],
        ], ['policy' => 'unanimous', 'min_distinct_providers' => 2]);

        $this->assertFalse($r['consensus']);
        $this->assertContains('security:sqli_risk', $r['dissents']);
        $this->assertStringContainsString('judge_consensus:', (string) $r['reason']);
    }

    public function test_correlated_panel_fails_independence(): void
    {
        // Three passing verdicts but all from the SAME engine — not independent verification.
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'hermes_cli', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'hermes_cli', 'passes' => true],
            ['lens' => 'security', 'provider' => 'hermes_cli', 'passes' => true],
        ], ['policy' => 'unanimous', 'min_distinct_providers' => 2]);

        $this->assertFalse($r['consensus'], 'a panel of clones is not independent verification');
        $this->assertContains('insufficient_independence:1<2_distinct_providers', $r['dissents']);
    }

    public function test_uncovered_required_lens_blocks_consensus(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'hermes_cli', 'passes' => true],
            ['lens' => 'correctness', 'provider' => 'minimax_m27', 'passes' => true],
        ], ['policy' => 'unanimous', 'required_lenses' => ['correctness', 'security'], 'min_distinct_providers' => 2]);

        $this->assertFalse($r['consensus'], 'security was never examined');
        $this->assertContains('uncovered_lens:security', $r['dissents']);
    }

    public function test_n_of_m_quorum_tolerates_a_minority_dissent(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'hermes_cli', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'minimax_m27', 'passes' => true],
            ['lens' => 'performance', 'provider' => 'codex', 'passes' => false, 'reason' => 'n_plus_1'],
        ], ['policy' => 'n_of_m', 'min_pass' => 2, 'required_lenses' => ['correctness', 'completeness'], 'min_distinct_providers' => 3]);

        $this->assertTrue($r['consensus'], '2-of-3 with both required lenses covered passes the quorum');
        $this->assertSame(2, $r['passed']);
    }

    public function test_empty_panel_is_not_consensus(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([], ['policy' => 'unanimous']);
        $this->assertFalse($r['consensus'], 'no judges => no trust');
    }
}

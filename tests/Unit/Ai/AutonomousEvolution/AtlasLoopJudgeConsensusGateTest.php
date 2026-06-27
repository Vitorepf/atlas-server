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

    public function test_source_class_floor_is_inert_by_default(): void
    {
        // OFF/byte-identical: the default fabricated set is two in_process judges; with no
        // min_distinct_source_classes (defaults to 1) consensus holds exactly as before.
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'adversarial_panel', 'source_class' => 'in_process', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'completeness_gate', 'source_class' => 'in_process', 'passes' => true],
        ], ['policy' => 'unanimous', 'min_distinct_providers' => 2]);

        $this->assertTrue($r['consensus']);
        $this->assertSame(1, $r['distinct_source_classes']);
    }

    public function test_armed_source_class_floor_blocks_self_refereed_consensus(): void
    {
        // ARMED: the same self-refereed set (2 distinct provider STRINGS, both in_process) satisfies
        // min_distinct_providers=2 but FAILS min_distinct_source_classes=2 — the author judging itself
        // no longer reaches consensus. This is the verdict-side twin of the S213 author≠judge refuse.
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'adversarial_panel', 'source_class' => 'in_process', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'completeness_gate', 'source_class' => 'in_process', 'passes' => true],
        ], ['policy' => 'unanimous', 'min_distinct_providers' => 2, 'min_distinct_source_classes' => 2]);

        $this->assertFalse($r['consensus']);
        $this->assertStringContainsString('insufficient_source_independence:1<2', (string) $r['reason']);
    }

    public function test_armed_source_class_floor_passes_with_a_real_external_source(): void
    {
        // A genuine cross-source verdict (one external judge added) clears the floor.
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'adversarial_panel', 'source_class' => 'in_process', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'completeness_gate', 'source_class' => 'in_process', 'passes' => true],
            ['lens' => 'correctness', 'provider' => 'minimax_m27', 'source_class' => 'external', 'passes' => true],
        ], ['policy' => 'unanimous', 'min_distinct_providers' => 2, 'min_distinct_source_classes' => 2]);

        $this->assertTrue($r['consensus']);
        $this->assertSame(2, $r['distinct_source_classes']);
    }

    public function test_distinct_provider_strings_in_same_class_do_not_fake_source_independence(): void
    {
        // ANTI-GAMING: two DIFFERENT provider strings that are both in_process must NOT satisfy the
        // source-class floor — the floor keys on the curated class, not the free-text provider field.
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'panel_a', 'source_class' => 'in_process', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'panel_b', 'source_class' => 'in_process', 'passes' => true],
        ], ['policy' => 'unanimous', 'min_distinct_source_classes' => 2]);

        $this->assertFalse($r['consensus']);
        $this->assertSame(2, $r['distinct_providers']);
        $this->assertSame(1, $r['distinct_source_classes']);
    }

    public function test_failing_external_judge_does_not_confer_source_independence(): void
    {
        // Only PASSING verdicts confer independence: a FAILING external judge cannot make a
        // self-refereed pass look cross-source (and its failure also blocks quorum).
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'adversarial_panel', 'source_class' => 'in_process', 'passes' => true],
            ['lens' => 'correctness', 'provider' => 'minimax_m27', 'source_class' => 'external', 'passes' => false, 'reason' => 'gap'],
        ], ['policy' => 'unanimous', 'min_distinct_source_classes' => 2]);

        $this->assertFalse($r['consensus']);
        $this->assertSame(1, $r['distinct_source_classes']);
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

    public function test_internal_fallback_verdicts_cover_default_required_lenses(): void
    {
        // Fix for the shipped-defaults footgun (adversarial workflow): the certifier's internal fallback
        // supplies BOTH default required lenses — correctness (adversarial panel) + completeness
        // (completeness gate) — across two distinct engines, so arming the gate with shipped defaults
        // reaches consensus for a genuinely good change instead of universal-refuting on uncovered_lens.
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'adversarial_panel', 'passes' => true],
            ['lens' => 'completeness', 'provider' => 'completeness_gate', 'passes' => true],
        ], ['policy' => 'unanimous', 'required_lenses' => ['correctness', 'completeness'], 'min_distinct_providers' => 2]);
        $this->assertTrue($r['consensus'], 'internal fallback covers both default lenses across 2 engines');
    }

    public function test_unknown_verdict_lens_fails_closed(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'corectness', 'provider' => 'a', 'passes' => true], // typo
            ['lens' => 'completeness', 'provider' => 'b', 'passes' => true],
        ], ['policy' => 'unanimous', 'min_distinct_providers' => 2]);
        $this->assertFalse($r['consensus'], 'a typo lens must not silently satisfy coverage');
        $this->assertContains('unknown_lens:corectness', $r['dissents']);
    }

    public function test_invalid_required_lens_fails_closed(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([
            ['lens' => 'correctness', 'provider' => 'a', 'passes' => true],
            ['lens' => 'correctness', 'provider' => 'b', 'passes' => true],
        ], ['policy' => 'unanimous', 'required_lenses' => ['correctness', 'secrity'], 'min_distinct_providers' => 2]);
        $this->assertFalse($r['consensus'], 'a typo in a required lens must fail closed, not be silently covered');
        $this->assertContains('invalid_required_lens:secrity', $r['dissents']);
    }

    public function test_empty_panel_is_not_consensus(): void
    {
        $r = (new AtlasLoopJudgeConsensusGate)->evaluate([], ['policy' => 'unanimous']);
        $this->assertFalse($r['consensus'], 'no judges => no trust');
    }
}

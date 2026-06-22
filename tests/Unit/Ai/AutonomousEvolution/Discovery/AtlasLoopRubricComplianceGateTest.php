<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIdeaDraftingRubric;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRubricComplianceGate as Gate;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT DD1 — rubric text + deterministic compliance gate (structural hard-reject, advisory kill-filter).
 */
final class AtlasLoopRubricComplianceGateTest extends TestCase
{
    private const PROBE = <<<TXT
    PROBE BLOCK
    Q1: correctness — evidence: app/Payment/Refund.php, app/Payment/Money.php
    Q2: assumes rounding is local — if dropped: one rounder centralizes it
    Q3: every caller re-rounds defensively
    Q4: yes — refund correctness depends on it
    Mechanism: new MoneyRounder; Hypothesis: removes drift; Observable: tests/Payment/RefundTest.php green
    TXT;

    private function resolver(array $existing): callable
    {
        return static fn (string $t): bool => in_array($t, $existing, true);
    }

    public function test_rubric_text_has_the_key_anchors(): void
    {
        $t = AtlasLoopIdeaDraftingRubric::text();
        $this->assertStringContainsString('PROBE BLOCK', $t);
        $this->assertStringContainsString('Four orthogonal moves', $t);
        $this->assertStringContainsString('Kill-filter', $t);
        $this->assertStringContainsString('correctness', $t);
    }

    public function test_valid_probe_with_two_resolvable_cases_passes(): void
    {
        $v = Gate::validate(self::PROBE, $this->resolver(['app/Payment/Refund.php', 'app/Payment/Money.php']));
        $this->assertTrue($v['ok'], implode(',', $v['reasons']));
    }

    public function test_missing_probe_block_is_rejected(): void
    {
        $text = "Mechanism: new thing in app/A.php and app/B.php";
        $v = Gate::validate($text, $this->resolver(['app/A.php', 'app/B.php']));
        $this->assertFalse($v['ok']);
        $this->assertContains('missing_probe_block', $v['reasons']);
    }

    public function test_insufficient_resolved_evidence_is_rejected(): void
    {
        // PROBE BLOCK present but only one cited file resolves.
        $v = Gate::validate(self::PROBE, $this->resolver(['app/Payment/Refund.php']));
        $this->assertFalse($v['ok']);
        $this->assertContains('insufficient_resolved_evidence', $v['reasons']);
    }

    public function test_kill_filter_warnings_are_advisory_not_rejections(): void
    {
        $text = self::PROBE."\nincrease the timeout and add more retries to be more robust";
        $v = Gate::validate($text, $this->resolver(['app/Payment/Refund.php', 'app/Payment/Money.php']));
        // structural checks pass => ok stays TRUE; the heuristics only WARN.
        $this->assertTrue($v['ok'], implode(',', $v['reasons']));
        $this->assertContains('maybe_single_knob', $v['warnings']);
        $this->assertContains('maybe_more_x', $v['warnings']);
    }

    public function test_re_treads_pruned_is_an_advisory_warning(): void
    {
        $w = Gate::killFilterWarnings('PROBE BLOCK uses caching layer memoization wrapper', ['caching', 'memoization', 'wrapper']);
        $this->assertContains('maybe_re_treads_pruned', $w);
    }

    public function test_admit_resolves_against_a_real_repo(): void
    {
        $root = sys_get_temp_dir().'/atlas-dd1-'.bin2hex(random_bytes(4));
        @mkdir($root.'/app/Payment', 0o755, true);
        file_put_contents($root.'/app/Payment/Refund.php', "<?php\n");
        file_put_contents($root.'/app/Payment/Money.php', "<?php\n");

        $v = (new Gate)->admit(self::PROBE, $root);

        (new \Symfony\Component\Process\Process(['rm', '-rf', $root]))->run();
        $this->assertTrue($v['ok'], implode(',', $v['reasons']));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHypothesisContractGate as Gate;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT HC1 — deterministic 4-line hypothesis contract gate (structural + evidence-resolution only).
 */
final class AtlasLoopHypothesisContractGateTest extends TestCase
{
    private const GOOD = <<<TXT
    Mechanism: extract the rounding step into a dedicated MoneyRounder in app/Payment/MoneyRounder.php
    Hypothesis: centralizing rounding removes the per-call drift seen in app/Payment/Refund.php
    Observable: tests/Payment/RefundTest.php goes green and the gate passes
    Conflicts: none — attacks an axis no pruned node touched
    TXT;

    /** @param callable(string):bool $resolver */
    private function resolver(array $existing): callable
    {
        return static fn (string $t): bool => in_array($t, $existing, true);
    }

    public function test_parses_four_labelled_lines(): void
    {
        $p = Gate::parse(self::GOOD);
        $this->assertNotEmpty($p['mechanism']);
        $this->assertNotEmpty($p['hypothesis']);
        $this->assertNotEmpty($p['observable']);
        $this->assertNotEmpty($p['conflicts']);
    }

    public function test_well_formed_contract_with_resolvable_evidence_passes(): void
    {
        $resolver = $this->resolver(['app/Payment/MoneyRounder.php', 'tests/Payment/RefundTest.php']);
        $v = Gate::validate(Gate::parse(self::GOOD), self::GOOD, $resolver);
        $this->assertTrue($v['ok'], implode(',', $v['reasons']));
    }

    public function test_missing_conflicts_is_rejected(): void
    {
        $text = "Mechanism: x in app/A.php\nHypothesis: y\nObservable: tests/AT.php green\n";
        $v = Gate::validate(Gate::parse($text), $text, $this->resolver(['app/A.php', 'tests/AT.php']));
        $this->assertFalse($v['ok']);
        $this->assertContains('missing_or_empty:conflicts', $v['reasons']);
    }

    public function test_unresolvable_evidence_is_rejected(): void
    {
        // 4 lines present but the cited paths do not exist => probe-disconnected.
        $v = Gate::validate(Gate::parse(self::GOOD), self::GOOD, $this->resolver([]));
        $this->assertFalse($v['ok']);
        $this->assertContains('evidence_unresolved', $v['reasons']);
    }

    public function test_no_evidence_token_is_rejected(): void
    {
        $text = "Mechanism: be more robust\nHypothesis: it gets better\nObservable: things improve\nConflicts: none\n";
        $v = Gate::validate(Gate::parse($text), $text, $this->resolver([]));
        $this->assertFalse($v['ok']);
        $this->assertContains('no_evidence_token', $v['reasons']);
    }

    public function test_fabricated_rubric_preamble_without_four_lines_is_rejected(): void
    {
        // The honor-gate attack: claim compliance in prose without the structure. Must NOT pass.
        $text = "I followed the idea_drafting rubric and the first-principles probe carefully. Trust me.";
        $v = Gate::validate(Gate::parse($text), $text, $this->resolver([]));
        $this->assertFalse($v['ok']);
        $this->assertContains('missing_or_empty:mechanism', $v['reasons']);
    }

    public function test_admit_resolves_against_a_real_repo_root(): void
    {
        $root = sys_get_temp_dir().'/atlas-hc1-'.bin2hex(random_bytes(4));
        @mkdir($root.'/app/Payment', 0o755, true);
        @mkdir($root.'/tests/Payment', 0o755, true);
        file_put_contents($root.'/app/Payment/MoneyRounder.php', "<?php\n");
        file_put_contents($root.'/tests/Payment/RefundTest.php', "<?php\n");

        $v = (new Gate)->admit(self::GOOD, $root);

        (new \Symfony\Component\Process\Process(['rm', '-rf', $root]))->run();
        $this->assertTrue($v['ok'], implode(',', $v['reasons']));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GOVERNED REFACTOR (Phase 1) — the FROZEN PROOF that the judge's complexityEarned sub-proof
 * (Guard 4b) certifies a refactor ONLY when behavior is preserved (the frozen sibling test
 * stays GREEN — the loop can never edit it) AND a real AST cyclomatic measure DROPS, and is
 * BYTE-IDENTICAL to today when the complexity_proof contract / flags are absent.
 *
 * These are the load-bearing tests from the plan: (i) certify only when behavior preserved AND
 * complexity drops; (ii) reject behavior-change; (iii) reject metric-gaming-by-deletion AND
 * no-op; (iv) default-inert when complexity_proof is absent.
 */
final class AtlasLoopRefactorComplexityProofTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_complexity_proof' => true]);

        $this->ws = sys_get_temp_dir().'/atlas-refactor-judge-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/src', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);

        // TARGET (the loop may edit this) — a deliberately HIGH-complexity classify() method:
        // a long if/elseif ladder. Behaviour: maps an int to a label. Cyclomatic is high.
        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    public function classify(int $n): string {
        if ($n === 0) { return 'zero'; }
        elseif ($n === 1) { return 'one'; }
        elseif ($n === 2) { return 'two'; }
        elseif ($n === 3) { return 'three'; }
        elseif ($n === 4) { return 'four'; }
        elseif ($n === 5) { return 'five'; }
        else { return 'many'; }
    }
}
PHP);

        // FROZEN sibling test (the loop must NEVER edit — tests/** is frozen). Plain `php`,
        // requires the target relatively, asserts behaviour, exits 0 only when behaviour holds.
        file_put_contents($this->ws.'/tests/classifier_test.php', <<<'PHP'
<?php
require __DIR__ . '/../src/Classifier.php';
$c = new Classifier();
$cases = [0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four',5=>'five',9=>'many'];
foreach ($cases as $in => $want) {
    if ($c->classify($in) !== $want) { fwrite(STDERR, "red at $in"); exit(1); }
}
echo 'green';
PHP);

        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->ws]))->run();
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->ws))->run();
    }

    /** The refactor acceptance contract: minimize + complexity_proof, frozen sibling test. */
    private function refactorAcceptance(): array
    {
        return [
            'commands' => ['php tests/classifier_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,
            'revert_recheck' => false,
        ];
    }

    public function test_certifies_only_when_behavior_preserved_AND_complexity_drops(): void
    {
        // A real, behaviour-preserving simplification: replace the if/elseif ladder with a
        // match() over a map — SAME behaviour, much lower cyclomatic on classify().
        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    public function classify(int $n): string {
        $map = [0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four',5=>'five'];
        return $map[$n] ?? 'many';
    }
}
PHP);

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->refactorAcceptance());

        $this->assertTrue($v['passed'], json_encode($v['details']));
        $proof = $v['details']['_complexity_reduction'] ?? null;
        $this->assertIsArray($proof, 'a verified refactor records the complexity proof');
        $this->assertTrue($proof['reduced']);
        $this->assertLessThan($proof['baseline_max'], $proof['candidate_max'], 'max-per-method cyclomatic dropped');
        $this->assertLessThanOrEqual($proof['baseline_total'], $proof['candidate_total'], 'file total did not increase');
        // The reported metric is the candidate AST max-per-method (lower = better), not a
        // provider-claimed number.
        $this->assertSame((float) $proof['candidate_max'], $v['metric']);
    }

    public function test_rejects_a_behavior_change_even_if_complexity_drops(): void
    {
        // Trivially low complexity, but WRONG behaviour (everything -> 'zero'): the frozen
        // sibling test goes RED. Guard 3 sinks it regardless of any complexity drop.
        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    public function classify(int $n): string { return 'zero'; }
}
PHP);

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->refactorAcceptance());

        $this->assertFalse($v['passed']);
        $this->assertSame('acceptance_command_failed', $v['details']['reason']);
    }

    public function test_rejects_metric_gaming_by_deletion_breaking_the_sibling_test(): void
    {
        // Delete a real branch to lower cyclomatic — but it breaks behaviour ('five' case
        // gone -> classify(5) returns 'many'), so the frozen sibling test goes RED (Guard 3).
        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    public function classify(int $n): string {
        $map = [0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four'];
        return $map[$n] ?? 'many';
    }
}
PHP);

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->refactorAcceptance());

        $this->assertFalse($v['passed'], 'deleting a branch breaks the frozen sibling test');
        $this->assertSame('acceptance_command_failed', $v['details']['reason']);
    }

    public function test_rejects_a_no_op_where_complexity_does_not_drop(): void
    {
        // Behaviour-preserving cosmetic edit (whitespace/comment) that keeps the SAME
        // if/elseif ladder: tests stay GREEN but complexity does NOT drop -> fail-closed.
        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    // a harmless comment, no structural change
    public function classify(int $n): string {
        if ($n === 0) { return 'zero'; }
        elseif ($n === 1) { return 'one'; }
        elseif ($n === 2) { return 'two'; }
        elseif ($n === 3) { return 'three'; }
        elseif ($n === 4) { return 'four'; }
        elseif ($n === 5) { return 'five'; }
        else { return 'many'; }
    }
}
PHP);

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->refactorAcceptance());

        $this->assertFalse($v['passed'], 'green tests but no complexity drop must be rejected');
        $this->assertSame('complexity_not_reduced', $v['details']['reason']);
        $proof = $v['details']['complexity_proof'] ?? null;
        $this->assertIsArray($proof);
        $this->assertFalse($proof['reduced']);
    }

    public function test_default_inert_without_complexity_proof_is_byte_identical_today(): void
    {
        // A gate acceptance (no complexity_proof, no minimize) behaves EXACTLY as today: the
        // simplification passes purely on the frozen test going green, and NO complexity proof
        // is computed. Proves the default-OFF safety at the judge level.
        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    public function classify(int $n): string {
        $map = [0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four',5=>'five'];
        return $map[$n] ?? 'many';
    }
}
PHP);

        $gateAcceptance = [
            'commands' => ['php tests/classifier_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
        ];
        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $gateAcceptance);

        $this->assertTrue($v['passed']);
        $this->assertSame('accepted', $v['details']['reason']);
        $this->assertSame(1.0, $v['metric'], 'gate metric is the legacy 1.0/0.0');
        $this->assertNull($v['details']['_complexity_reduction'] ?? null, 'no complexity proof on the default path');
    }

    public function test_complexity_proof_ignored_when_config_flag_off(): void
    {
        // With the operator flag OFF, even a complexity_proof+minimize contract must NOT
        // enter Guard 4b: a green no-op (same ladder) passes as a plain minimize gate would,
        // proving the flag is the master switch and the judge is byte-identical when OFF.
        config(['atlas.loop.refactor_complexity_proof' => false]);

        file_put_contents($this->ws.'/src/Classifier.php', <<<'PHP'
<?php
class Classifier {
    public function classify(int $n): string {
        if ($n === 0) { return 'zero'; }
        elseif ($n === 1) { return 'one'; }
        elseif ($n === 2) { return 'two'; }
        elseif ($n === 3) { return 'three'; }
        elseif ($n === 4) { return 'four'; }
        elseif ($n === 5) { return 'five'; }
        else { return 'many'; }
    }
}
PHP);
        // a harmless behaviour-preserving touch so there IS a diff (still same ladder)
        file_put_contents($this->ws.'/src/Classifier.php', file_get_contents($this->ws.'/src/Classifier.php')."\n// touch\n");

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->refactorAcceptance());

        // No complexity proof was run; minimize metric with no pattern on a passing run = 1.0.
        $this->assertTrue($v['passed'], 'flag OFF => no Guard 4b => green no-op passes like a plain gate');
        $this->assertNull($v['details']['_complexity_reduction'] ?? null);
    }
}

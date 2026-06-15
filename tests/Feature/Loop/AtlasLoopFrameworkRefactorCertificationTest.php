<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * FRAMEWORK REFACTOR — the FROZEN PROOF that the semantic certifier's complexity-drop gate
 * certifies a refactor ONLY when BOTH hold:
 *   (a) the target's REAL tests pass (behavior preserved — the deterministic gate re-runs them), AND
 *   (b) a REAL AST max-per-method cyclomatic DROPS (file total not increasing), measured by the
 *       certifier's OWN analyzer in the gate workspace (never a provider-claimed number).
 *
 * Ungameable: behavior by REAL tests, complexity by AST. A no-op / complexity-non-reducing diff
 * is NOT certified (complexity_not_reduced); a behavior change turns the real test RED (deterministic
 * gate); a HEAVY (>15-line) structural refactor that genuinely simplifies IS certified.
 */
final class AtlasLoopFrameworkRefactorCertificationTest extends TestCase
{
    private string $workspace;

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    /** The refactor acceptance contract: minimize + complexity_proof, real frozen test. */
    private function refactorAcceptance(): array
    {
        return [
            'commands' => ['php tests/ClassifierTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,
            'revert_recheck' => false,
            'timeout_seconds' => 30,
        ];
    }

    public function test_certifies_when_tests_green_AND_complexity_drops(): void
    {
        // baseline = high-cyclomatic if/elseif ladder; candidate = match-map simplification.
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $this->lowComplexity());

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $this->refactorAcceptance(),
            ['objective' => 'Refactor Classifier to reduce complexity', 'allowed_files' => ['src/Classifier.php']],
        );

        $this->assertTrue($receipt['certified'], json_encode($receipt['reasons']));
        $proof = $receipt['complexity_proof'];
        $this->assertIsArray($proof);
        $this->assertTrue($proof['reduced']);
        $this->assertLessThan($proof['baseline_max'], $proof['candidate_max'], 'max-per-method cyclomatic dropped');
        $this->assertLessThanOrEqual($proof['baseline_total'], $proof['candidate_total'], 'file total did not increase');
        $this->assertTrue(data_get($receipt, 'evidence.complexity_reduced'));
    }

    public function test_heavy_multi_statement_refactor_diff_is_certified_not_rejected_by_a_size_cap(): void
    {
        // A HEAVY (>15-line) structural refactor: extract the giant method into a dispatch table +
        // small helpers. Behavior identical; worst-method cyclomatic drops sharply; total stays flat
        // or lower. Proves no small-diff cap blocks a large behavior-preserving refactor.
        $heavyCandidate = <<<'PHP'
<?php
final class Classifier
{
    /** @var array<int,string> */
    private array $labels = [
        0 => 'zero',
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
    ];

    public function classify(int $n): string
    {
        return $this->labels[$n] ?? $this->fallback();
    }

    private function fallback(): string
    {
        return 'many';
    }
}
PHP;
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $heavyCandidate);

        // Sanity: the diff is genuinely heavy (> 15 changed lines).
        $stat = new Process(['git', 'diff', '--numstat', '--no-ext-diff'], $this->workspace, null, null, 30.0);
        $stat->run();
        $changed = 0;
        foreach (preg_split('/\R/', trim((string) $stat->getOutput())) ?: [] as $row) {
            if (preg_match('/^(\d+)\s+(\d+)\s+/', $row, $m) === 1) {
                $changed += (int) $m[1] + (int) $m[2];
            }
        }
        $this->assertGreaterThan(15, $changed, 'the refactor diff is heavy by construction');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $this->refactorAcceptance(),
            ['objective' => 'Refactor Classifier — heavy extraction', 'allowed_files' => ['src/Classifier.php']],
        );

        $this->assertTrue($receipt['certified'], 'a heavy refactor that preserves behavior + drops complexity certifies: '.json_encode($receipt['reasons']));
        $this->assertTrue($receipt['complexity_proof']['reduced']);
    }

    public function test_match_map_refactor_certifies_with_decision_aware_flag_ON(): void
    {
        // PROOF the refinement is a PURE WIN: with the refactor-decision-aware flag ON, the match-map
        // simplification (whose added lines have a COVERED relocated literal but NO decision operator)
        // CERTIFIES via the cosmetic-fallback KILL tier — instead of the pre-refinement hard
        // mutation_adequacy_gate:no_applicable_mutation that FALSELY rejected it. The complexity drop +
        // frozen behaviour test still both hold, so the conjunction certifies.
        config(['atlas.loop.mutation_adequacy_gate.refactor_decision_aware' => true]);
        config(['atlas.loop.mutation_adequacy_gate.enabled' => true]);

        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $this->lowComplexity());

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $this->refactorAcceptance(),
            ['objective' => 'Refactor Classifier to reduce complexity', 'allowed_files' => ['src/Classifier.php']],
        );

        $this->assertTrue($receipt['certified'], 'flag ON must still certify the match-map refactor: '.json_encode($receipt['reasons']));
        $this->assertTrue($receipt['complexity_proof']['reduced']);
        // The mutation gate certified via a KILLED cosmetic (the relocated literal proves the test
        // exercises the new code), NOT a hard no_applicable_mutation reject.
        $this->assertSame('mutation_killed', data_get($receipt, 'mutation_adequacy_gate.status'));
        $this->assertTrue(data_get($receipt, 'mutation_adequacy_gate.certified'));
        $this->assertNotContains('mutation_adequacy_gate:no_applicable_mutation', $receipt['reasons']);
    }

    public function test_heavy_extraction_refactor_certifies_with_decision_aware_flag_ON(): void
    {
        // The same PURE-WIN proof for the HEAVY (>15-line) dispatch-table extraction: with the flag ON
        // it certifies via the cosmetic-fallback tier rather than the pre-refinement false reject.
        config(['atlas.loop.mutation_adequacy_gate.refactor_decision_aware' => true]);
        config(['atlas.loop.mutation_adequacy_gate.enabled' => true]);

        $heavyCandidate = <<<'PHP'
<?php
final class Classifier
{
    /** @var array<int,string> */
    private array $labels = [
        0 => 'zero',
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
    ];

    public function classify(int $n): string
    {
        return $this->labels[$n] ?? $this->fallback();
    }

    private function fallback(): string
    {
        return 'many';
    }
}
PHP;
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $heavyCandidate);

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $this->refactorAcceptance(),
            ['objective' => 'Refactor Classifier — heavy extraction', 'allowed_files' => ['src/Classifier.php']],
        );

        $this->assertTrue($receipt['certified'], 'flag ON must still certify the heavy refactor: '.json_encode($receipt['reasons']));
        $this->assertTrue($receipt['complexity_proof']['reduced']);
        $this->assertTrue(data_get($receipt, 'mutation_adequacy_gate.certified'));
        $this->assertNotContains('mutation_adequacy_gate:no_applicable_mutation', $receipt['reasons']);
    }

    public function test_not_certified_when_a_refactor_breaks_a_real_test(): void
    {
        // candidate has WRONG behavior (everything -> 'zero'): the real frozen test goes RED, so the
        // deterministic gate refutes regardless of any complexity drop.
        $broken = "<?php\nfinal class Classifier {\n    public function classify(int \$n): string { return 'zero'; }\n}\n";
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $broken);

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $this->refactorAcceptance(),
            ['objective' => 'Refactor Classifier', 'allowed_files' => ['src/Classifier.php']],
        );

        $this->assertFalse($receipt['certified'], 'a behavior-changing refactor must not certify');
        $this->assertNotContains('certified', $receipt['reasons']);
    }

    public function test_not_certified_when_complexity_is_not_reduced_noop(): void
    {
        // A behavior-preserving cosmetic edit (comment) that keeps the SAME ladder: the real test
        // stays GREEN but complexity does NOT drop -> NOT certified (complexity_not_reduced).
        $noop = str_replace(
            'public function classify(int $n): string',
            "// harmless comment\n    public function classify(int \$n): string",
            $this->highComplexity(),
        );
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $noop);

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $this->refactorAcceptance(),
            ['objective' => 'Refactor Classifier', 'allowed_files' => ['src/Classifier.php']],
        );

        $this->assertFalse($receipt['certified'], 'green tests but no complexity drop must not certify');
        $this->assertContains('complexity_gate:complexity_not_reduced', $receipt['reasons']);
        $this->assertFalse($receipt['complexity_proof']['reduced']);
    }

    public function test_vanilla_implementation_contract_skips_the_complexity_gate(): void
    {
        // An ordinary GATE acceptance (no complexity_proof) must NOT enter the complexity gate at
        // all (byte-identical to today). The baseline is RED (returns 'bad'); the candidate EARNS
        // the green by implementing the asserted behavior — a vanilla new-behavior proposal.
        $baseline = "<?php\nfinal class Foo {\n    public function value(): string { return 'bad'; }\n}\n";
        $candidate = "<?php\nfinal class Foo {\n    public function value(): string { return 'good'; }\n}\n";
        $this->workspace = $this->workspaceWithFoo($baseline, $candidate);

        $gateAcceptance = [
            'commands' => ['php tests/FooTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify(
            $this->workspace,
            $gateAcceptance,
            ['objective' => 'make Foo return good', 'allowed_files' => ['src/Foo.php']],
        );

        $this->assertTrue($receipt['certified'], json_encode($receipt['reasons']));
        $this->assertFalse(data_get($receipt, 'evidence.complexity_proof_required'), 'no complexity gate on a vanilla contract');
        $this->assertNull($receipt['complexity_proof'], 'no complexity proof computed on the default path');
    }

    /** A git workspace for a vanilla (new-behavior) Foo proposal: baseline RED, candidate earns green. */
    private function workspaceWithFoo(string $baseline, string $candidate): string
    {
        $dir = sys_get_temp_dir().'/atlas-fw-refactor-cert-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Foo.php', $baseline);
        file_put_contents($dir.'/tests/FooTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Foo.php';
$foo = new Foo();
if ($foo->value() !== 'good') { fwrite(STDERR, 'expected good'); exit(1); }
echo 'green';
PHP);
        $this->runGit(['git', 'init', '-q'], $dir);
        $this->runGit(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runGit(['git', 'config', 'commit.gpgsign', 'false'], $dir);
        $this->runGit(['git', 'add', '-A'], $dir);
        $this->runGit(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Foo.php', $candidate);

        return $dir;
    }

    private function highComplexity(): string
    {
        return <<<'PHP'
<?php
final class Classifier
{
    public function classify(int $n): string
    {
        if ($n === 0) { return 'zero'; }
        elseif ($n === 1) { return 'one'; }
        elseif ($n === 2) { return 'two'; }
        elseif ($n === 3) { return 'three'; }
        elseif ($n === 4) { return 'four'; }
        elseif ($n === 5) { return 'five'; }
        elseif ($n === 6) { return 'six'; }
        elseif ($n === 7) { return 'seven'; }
        elseif ($n === 8) { return 'eight'; }
        else { return 'many'; }
    }
}
PHP;
    }

    private function lowComplexity(): string
    {
        return <<<'PHP'
<?php
final class Classifier
{
    public function classify(int $n): string
    {
        $map = [0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four',5=>'five',6=>'six',7=>'seven',8=>'eight'];
        return $map[$n] ?? 'many';
    }
}
PHP;
    }

    /**
     * A git workspace with a committed BASELINE (the original target + its REAL test) and the
     * candidate refactor LIVE in the working tree — exactly the gate-workspace state the certifier
     * sees, so the complexity gate can stash to baseline and measure both sides.
     */
    private function workspaceWithRefactor(string $baseline, string $candidate): string
    {
        $dir = sys_get_temp_dir().'/atlas-fw-refactor-cert-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Classifier.php', $baseline);
        file_put_contents($dir.'/tests/ClassifierTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Classifier.php';
$c = new Classifier();
$cases = [0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four',5=>'five',6=>'six',7=>'seven',8=>'eight',42=>'many'];
foreach ($cases as $in => $want) {
    if ($c->classify($in) !== $want) { fwrite(STDERR, "red at $in"); exit(1); }
}
echo 'green';
PHP);
        $this->runGit(['git', 'init', '-q'], $dir);
        $this->runGit(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runGit(['git', 'config', 'commit.gpgsign', 'false'], $dir);
        $this->runGit(['git', 'add', '-A'], $dir);
        $this->runGit(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        // Candidate refactor goes LIVE in the working tree (the gate workspace state).
        file_put_contents($dir.'/src/Classifier.php', $candidate);

        return $dir;
    }

    /** @param list<string> $argv */
    private function runGit(array $argv, string $cwd): void
    {
        $p = new Process($argv, $cwd, null, null, 30.0);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput() ?: $p->getOutput());
    }
}

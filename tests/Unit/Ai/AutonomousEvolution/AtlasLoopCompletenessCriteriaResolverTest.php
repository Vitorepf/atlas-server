<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossFileConsumerGateService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCompletenessCriteriaResolver;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * item9 — MACHINE-VERIFIED completeness criteria resolver. The resolver DERIVES criteria only from
 * already-trusted signals and RESOLVES each `satisfied` by RE-RUNNING its bound command/metric in the
 * candidate workspace, never model-declared. Empty-derivable => [] (fail-open preserved).
 */
final class AtlasLoopCompletenessCriteriaResolverTest extends TestCase
{
    private string $workspace;

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }

        parent::tearDown();
    }

    public function test_empty_derivable_returns_empty_array(): void
    {
        // No commands, no complexity_proof, no consumer gate => nothing derivable => [] (fail-open).
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, null);

        $criteria = $resolver->resolve('do something', [
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
        ], sys_get_temp_dir(), []);

        $this->assertSame([], $criteria, 'empty-derivable must return [] so the gate stays fail-open');
    }

    public function test_acceptance_command_passing_yields_satisfied_criterion(): void
    {
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, null);

        $criteria = $resolver->resolve('exit zero', [
            'commands' => ['php -r "exit(0);"'],
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
        ], sys_get_temp_dir(), []);

        $this->assertCount(1, $criteria);
        $this->assertStringStartsWith('acceptance_command_', (string) $criteria[0]['id']);
        $this->assertTrue($criteria[0]['satisfied'], 'a passing command (exit 0) resolves satisfied=true');
        $this->assertTrue($criteria[0]['required']);
    }

    public function test_acceptance_command_failing_yields_unsatisfied_criterion(): void
    {
        // Proves the resolver RE-RUNS the command (not model-declared): a non-zero exit => satisfied=false.
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, null);

        $criteria = $resolver->resolve('exit one', [
            'commands' => ['php -r "exit(1);"'],
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
        ], sys_get_temp_dir(), []);

        $this->assertCount(1, $criteria);
        $this->assertFalse($criteria[0]['satisfied'], 'a failing command (exit 1) resolves satisfied=false');
        $this->assertTrue($criteria[0]['required']);
    }

    public function test_refactor_god_class_criterion_resolved_by_real_measurement(): void
    {
        // A real complexity drop (high-cyclomatic ladder -> match-map) => class_no_longer_god satisfied.
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $this->lowComplexity());
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, new AtlasLoopSignalAnalyzer);

        $criteria = $resolver->resolve('Refactor Classifier', [
            'complexity_proof' => true,
            'metric_kind' => 'minimize',
            'timeout_seconds' => 30,
        ], $this->workspace, ['src/Classifier.php']);

        $this->assertCount(1, $criteria);
        $this->assertSame('class_no_longer_god', $criteria[0]['id']);
        $this->assertTrue($criteria[0]['satisfied'], 'a real measured complexity drop => satisfied=true');
        $this->assertTrue($criteria[0]['required']);
    }

    public function test_refactor_god_class_criterion_noop_is_unsatisfied(): void
    {
        // A behaviour-preserving cosmetic edit that keeps the SAME ladder: no complexity drop =>
        // class_no_longer_god satisfied=false (fail-closed for the refactor criterion).
        $noop = str_replace(
            'public function classify(int $n): string',
            "// harmless comment\n    public function classify(int \$n): string",
            $this->highComplexity(),
        );
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $noop);
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, new AtlasLoopSignalAnalyzer);

        $criteria = $resolver->resolve('Refactor Classifier', [
            'complexity_proof' => true,
            'metric_kind' => 'minimize',
            'timeout_seconds' => 30,
        ], $this->workspace, ['src/Classifier.php']);

        $this->assertCount(1, $criteria);
        $this->assertSame('class_no_longer_god', $criteria[0]['id']);
        $this->assertFalse($criteria[0]['satisfied'], 'a no-op candidate => satisfied=false (fail-closed)');
    }

    public function test_refactor_god_class_criterion_fail_closed_with_null_analyzer(): void
    {
        // DEFECT 1+2 closure: with a NULL analyzer the refactor criterion is satisfied=false (it can
        // never be measured), so an armed gate refutes rather than false-passing. The integrator binds
        // a live analyzer so production actually measures.
        $this->workspace = $this->workspaceWithRefactor($this->highComplexity(), $this->lowComplexity());
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, null);

        $criteria = $resolver->resolve('Refactor Classifier', [
            'complexity_proof' => true,
            'metric_kind' => 'minimize',
            'timeout_seconds' => 30,
        ], $this->workspace, ['src/Classifier.php']);

        $this->assertCount(1, $criteria);
        $this->assertSame('class_no_longer_god', $criteria[0]['id']);
        $this->assertFalse($criteria[0]['satisfied'], 'null analyzer => fail-closed satisfied=false');
    }

    public function test_consumer_contract_skip_does_not_emit_criterion(): void
    {
        // A null cross-file gate emits no consumer criterion (fail-open: an unverifiable consumer is
        // not a failing requirement).
        $resolver = new AtlasLoopCompletenessCriteriaResolver(null, null);
        $criteria = $resolver->resolve('do something', [
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
        ], sys_get_temp_dir(), ['src/Foo.php']);

        $this->assertSame([], $criteria, 'null cross-file gate => no consumer criterion (fail-open)');

        // A REAL gate whose discovered consumer carries NO runnable command produces a SKIPPED run
        // (consumer_command_missing_skipped). The resolver must emit NO criterion for it.
        $skipResolver = new AtlasLoopCompletenessCriteriaResolver(app(AtlasLoopCrossFileConsumerGateService::class), null);
        $skipCriteria = $skipResolver->resolve('do something', [
            'commands' => ['php -r "exit(0);"'],
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
            // An explicit consumer contract WITHOUT a command => the gate records it skipped.
            'consumer_contracts' => [['changed_symbol' => 'Foo', 'consumer_file' => 'app/Bar.php']],
        ], sys_get_temp_dir(), ['src/Foo.php']);

        foreach ($skipCriteria as $c) {
            $this->assertStringStartsNotWith('consumer_contract_', (string) $c['id'], 'a skipped consumer run emits no consumer criterion');
        }
    }

    public function test_consumer_contract_ran_yields_resolved_criterion(): void
    {
        // A NON-skipped consumer contract that carries a runnable command emits a criterion whose
        // satisfied is RESOLVED by the cross-file gate re-running that command (not model-declared).
        $resolver = new AtlasLoopCompletenessCriteriaResolver(app(AtlasLoopCrossFileConsumerGateService::class), null);

        $criteria = $resolver->resolve('do something', [
            'commands' => ['php -r "exit(0);"'],
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
            // A passing consumer (exit 0) and a failing one (exit 1), both with explicit symbols+commands.
            'consumer_contracts' => [
                ['changed_symbol' => 'Foo', 'consumer_file' => 'app/Bar.php', 'command' => 'php -r "exit(0);"'],
                ['changed_symbol' => 'Foo', 'consumer_file' => 'app/Baz.php', 'command' => 'php -r "exit(1);"'],
            ],
        ], sys_get_temp_dir(), ['src/Foo.php']);

        $consumer = array_values(array_filter($criteria, static fn (array $c): bool => str_starts_with((string) $c['id'], 'consumer_contract_')));
        $this->assertNotEmpty($consumer, 'a runnable consumer contract emits a consumer criterion');
        foreach ($consumer as $c) {
            $this->assertTrue($c['required']);
            $this->assertIsBool($c['satisfied']);
        }
        // At least one passing and one failing consumer were resolved (machine re-run, not declared).
        $satisfaction = array_map(static fn (array $c): bool => (bool) $c['satisfied'], $consumer);
        $this->assertContains(true, $satisfaction, 'the exit-0 consumer resolved satisfied=true');
        $this->assertContains(false, $satisfaction, 'the exit-1 consumer resolved satisfied=false');
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
     * A git workspace with a committed BASELINE and the candidate refactor LIVE in the working tree —
     * exactly the gate-workspace state the resolver sees, so source (C) can stash to baseline and
     * measure both sides.
     */
    private function workspaceWithRefactor(string $baseline, string $candidate): string
    {
        $dir = sys_get_temp_dir().'/atlas-completeness-resolver-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        file_put_contents($dir.'/src/Classifier.php', $baseline);
        $this->runGit(['git', 'init', '-q'], $dir);
        $this->runGit(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runGit(['git', 'config', 'commit.gpgsign', 'false'], $dir);
        $this->runGit(['git', 'add', '-A'], $dir);
        $this->runGit(['git', 'commit', '-q', '-m', 'baseline'], $dir);
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

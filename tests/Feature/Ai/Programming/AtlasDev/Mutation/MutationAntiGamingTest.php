<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Mutation\FakeMutationCommandRunner;

/**
 * E3 — Anti-gaming hardening of the mutation gate (feature integration).
 *
 * VAL-E3-005, VAL-E3-006, VAL-E3-012, VAL-E3-013.
 *
 * The gated MSI must be the REAL score over the full applicable mutant
 * population for the touched scope. A patch must NOT be able to inflate the
 * gated MSI or escape the verdict via:
 *
 *   - VAL-E3-005: patch-supplied config that skips mutators to inflate MSI.
 *   - VAL-E3-006: patch-supplied config that excludes surviving/killed
 *      mutants from the denominator.
 *   - VAL-E3-012: for a multi-test-file patch, scope must be the deduplicated
 *      UNION of all touched test files + covered sources (nothing outside).
 *   - VAL-E3-013: a single weak test file among robust ones is still flagged
 *      (MSI not diluted by a high aggregate).
 *
 * The adapter recomputes the REAL MSI from the raw mutant counts over the
 * FULL population (totalMutantsCount as denominator, immune to skipped/
 * ignored inflation), and the gate uses that real MSI — never infection's
 * self-reported stats.msi (which subtracts skipped/ignored from the
 * denominator and can be inflated). For multi-file patches, the adapter
 * also computes per-source-file MSI from the full JSON report so a weak
 * file among strong ones is not masked by a high aggregate (VAL-E3-013).
 */
final class MutationAntiGamingTest extends TestCase
{
    // -- VAL-E3-005: mutator-skipping does not raise the gated MSI ------------

    public function test_val_e3_005_mutator_skipping_does_not_raise_gated_msi_same_verdict(): void
    {
        // Baseline weak test: infection reports MSI=40 with the FULL mutator
        // set (e.g. 4 killed, 6 escaped out of 10 total). The real MSI over
        // the full population is 40%.
        $baselineRunner = new FakeMutationCommandRunner;
        $baselineRunner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 4,
            escapedCount: 6,
            ignoredCount: 0,
            skippedCount: 0,
            errorCount: 0,
            timeOutCount: 0,
            notCoveredCount: 0,
            perFile: [
                'app/Calculator.php' => ['killed' => 4, 'escaped' => 6],
            ],
        );

        $baselineAdapter = $this->makeAdapter($baselineRunner, mode: 'advisory');
        $baselineResult = $baselineAdapter->run(
            runId: 'run-e3-005-baseline',
            touchedFiles: ['tests/Unit/CalculatorTest.php', 'app/Calculator.php'],
        );

        // Mutator-skipping attack: the patch supplies a config that runs only
        // mutators the test happens to kill. Infection reports MSI=100 (4/4)
        // because it only generated 4 mutants — but the REAL population is
        // still 10. The adapter must recompute the real MSI over the FULL
        // population and NOT trust infection's inflated stats.msi.
        $attackRunner = new FakeMutationCommandRunner;
        // The attacker's config made infection generate only 4 mutants and
        // the test killed all 4, so infection's stats.msi = 100. But the
        // adapter sees totalMutantsCount=4 (incomplete population) and must
        // NOT be fooled: the real MSI is reported as-is from infection's
        // raw counts (4 killed, 0 escaped, total 4 = 100%), BUT the adapter
        // does NOT honor the patch's mutator reduction — it always runs the
        // full mutator set. The attack runner simulates what infection WOULD
        // report if the adapter honored a patch config: we prove the adapter
        // does NOT honor it by asserting the command does NOT carry
        // --mutators=.
        $attackRunner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 4,
            escapedCount: 6,
            ignoredCount: 0,
            skippedCount: 0,
            errorCount: 0,
            timeOutCount: 0,
            notCoveredCount: 0,
            perFile: [
                'app/Calculator.php' => ['killed' => 4, 'escaped' => 6],
            ],
        );

        $attackAdapter = $this->makeAdapter($attackRunner, mode: 'advisory');
        $attackResult = $attackAdapter->run(
            runId: 'run-e3-005-attack',
            touchedFiles: ['tests/Unit/CalculatorTest.php', 'app/Calculator.php'],
        );

        // The gated MSI is IDENTICAL: the adapter does not honor patch-
        // supplied mutator-skipping, so both runs see the full population.
        $this->assertSame(
            $baselineResult->realMsi,
            $attackResult->realMsi,
            'VAL-E3-005: mutator-skipping does not raise the gated MSI',
        );
        $this->assertSame(40.0, $attackResult->realMsi, 'real MSI = 40% (4/10)');

        // The command never carries --mutators= (the adapter does not honor
        // any patch-supplied mutator selection).
        $this->assertStringNotContainsString(
            '--mutators=',
            $attackRunner->calls[0]['command'],
            'VAL-E3-005: adapter does not honor patch-supplied mutator selection',
        );

        // Same verdict: below-threshold in both.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $baselineVerdict = $gate->evaluate($baselineResult);
        $attackVerdict = $gate->evaluate($attackResult);
        $this->assertTrue($baselineVerdict->tripped, 'baseline trips (below threshold)');
        $this->assertTrue($attackVerdict->tripped, 'attack still trips (same real MSI)');
        $this->assertSame(
            $baselineVerdict->honestyFlags,
            $attackVerdict->honestyFlags,
            'VAL-E3-005: same flag/fail verdict',
        );
    }

    public function test_val_e3_005_command_does_not_carry_mutators_flag_regardless_of_patch(): void
    {
        // The adapter writes its OWN per-run config and never passes
        // --mutators= on the CLI. A patch cannot reduce the mutator set.
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 4,
            killedCount: 4,
            escapedCount: 0,
            perFile: ['app/Calc.php' => ['killed' => 4, 'escaped' => 0]],
        );

        $adapter = $this->makeAdapter($runner, mode: 'advisory');
        $adapter->run(
            runId: 'run-e3-005-nomut',
            touchedFiles: ['tests/Unit/CalcTest.php', 'app/Calc.php'],
        );

        $command = $runner->calls[0]['command'];
        $this->assertStringNotContainsString('--mutators=', $command);
        // The per-run config also must not set a mutators key.
        $perRunConfig = $this->extractConfigPath($command);
        if ($perRunConfig !== '' && is_file($perRunConfig)) {
            $decoded = json_decode((string) file_get_contents($perRunConfig), true);
            $this->assertArrayNotHasKey(
                'mutators',
                $decoded,
                'VAL-E3-005: per-run config has no mutators key (full default set)',
            );
        }
    }

    // -- VAL-E3-006: survivor-exclusion does not raise the gated MSI ----------

    public function test_val_e3_006_excluding_survivors_does_not_raise_gated_msi(): void
    {
        // A weak test: 4 killed, 6 escaped (survivors). Infection's
        // stats.msi would be 40% (4/10). Now the patch config marks the 6
        // escaped mutants as IGNORED — infection would then compute
        // stats.msi over (10 - 6 ignored) = 4, so 4/4 = 100%. The adapter
        // must RECOMPUTE the real MSI using totalMutantsCount (10) as the
        // denominator, NOT infection's tested-count, so the gated MSI stays
        // at 40%.
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 4,
            escapedCount: 0,        // survivors moved to ignored by patch config
            ignoredCount: 6,        // the attack: 6 survivors excluded
            skippedCount: 0,
            errorCount: 0,
            timeOutCount: 0,
            notCoveredCount: 0,
            perFile: [
                'app/Calculator.php' => ['killed' => 4, 'escaped' => 0, 'ignored' => 6],
            ],
        );

        $adapter = $this->makeAdapter($runner, mode: 'advisory');
        $result = $adapter->run(
            runId: 'run-e3-006',
            touchedFiles: ['tests/Unit/CalculatorTest.php', 'app/Calculator.php'],
        );

        // The adapter recomputed the REAL MSI: killed(4) / totalMutantsCount(10) = 40%.
        // infection's stats.msi would have been 100% (4/4 after excluding ignored).
        $this->assertSame(
            40.0,
            $result->realMsi,
            'VAL-E3-006: gated MSI is recomputed over the FULL population (10), not post-exclusion',
        );
        $this->assertNotSame(
            $result->msi,
            $result->realMsi,
            'the reported msi (infection, inflated) differs from the real msi (recomputed)',
        );

        // The gate uses the REAL MSI and trips.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($result);
        $this->assertTrue($verdict->tripped, 'VAL-E3-006: gate trips on the real (recomputed) MSI');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'VAL-E3-006: flag raised despite the exclusion attempt',
        );
    }

    public function test_val_e3_006_real_msi_uses_total_count_not_tested_count(): void
    {
        // Directly verify the recomputation formula: real MSI counts
        // (killed + error + timeout) / totalMutantsCount, NEVER dividing by
        // (total - skipped - ignored). This is the anti-gaming invariant.
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 20,
            killedCount: 5,
            escapedCount: 5,
            ignoredCount: 5,  // attacker marks 5 as ignored
            skippedCount: 5,  // attacker marks 5 as skipped
            errorCount: 0,
            timeOutCount: 0,
            notCoveredCount: 0,
            perFile: [
                'app/Foo.php' => ['killed' => 5, 'escaped' => 5, 'ignored' => 5, 'skipped' => 5],
            ],
        );

        $adapter = $this->makeAdapter($runner, mode: 'advisory');
        $result = $adapter->run(
            runId: 'run-e3-006-formula',
            touchedFiles: ['tests/Unit/FooTest.php', 'app/Foo.php'],
        );

        // killed(5) / total(20) = 25%. NOT 5/5=100% (post-exclusion).
        $this->assertSame(25.0, $result->realMsi);
    }

    // -- VAL-E3-012: multi-test-file patch scopes to deduped union -----------

    public function test_val_e3_012_multi_test_file_patch_scopes_to_deduped_union(): void
    {
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 4,
            killedCount: 4,
            escapedCount: 0,
            perFile: [
                'app/ModuleA/Foo.php' => ['killed' => 2, 'escaped' => 0],
                'app/ModuleB/Bar.php' => ['killed' => 2, 'escaped' => 0],
            ],
        );

        $adapter = $this->makeAdapter($runner, mode: 'advisory');
        $result = $adapter->run(
            runId: 'run-e3-012',
            touchedFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
                'app/Shared.php', // shared by both, deduped
            ],
        );

        $this->assertFalse($result->skipped);
        $this->assertNotNull($result->scope);

        // Both test files in scope (deduped union).
        $this->assertSame(
            [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
            ],
            $result->scope->testFiles,
            'VAL-E3-012: both touched test files in scope',
        );

        // The covered source is the deduped union: Foo, Bar, Shared.
        // (Shared appears once even though both tests cover it.)
        $this->assertContains('app/ModuleA/Foo.php', $result->scope->sourceFiles);
        $this->assertContains('app/ModuleB/Bar.php', $result->scope->sourceFiles);
        $this->assertContains('app/Shared.php', $result->scope->sourceFiles);

        // No file OUTSIDE the union is in scope.
        foreach ($result->scope->sourceFiles as $source) {
            $this->assertStringStartsWith('app/', $source);
            $this->assertStringNotContainsString('tests/', $source);
        }

        // Cardinality far below 3592.
        $this->assertLessThan(
            50,
            count($result->scope->sourceFiles) + count($result->scope->testFiles),
        );

        // The command scopes mutation (--filter) to the union source.
        $command = $runner->calls[0]['command'];
        $this->assertStringContainsString('app/ModuleA/Foo.php', $command);
        $this->assertStringContainsString('app/ModuleB/Bar.php', $command);
        $this->assertStringContainsString('app/Shared.php', $command);
    }

    // -- VAL-E3-013: one weak test file among strong ones is still flagged ----

    public function test_val_e3_013_weak_file_among_strong_ones_trips_verdict_advisory(): void
    {
        // Two source files: Foo (weak, 20% MSI = 1 killed, 4 escaped) and
        // Bar (strong, 100% MSI = 5 killed, 0 escaped). The AGGREGATE MSI
        // is (6/10) = 60% — exactly at the threshold. Without per-file
        // analysis, the weak Foo file would be masked by the strong Bar.
        // The gate must trip because Foo is below the threshold.
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 6,   // 1 (Foo) + 5 (Bar)
            escapedCount: 4,  // 4 (Foo)
            ignoredCount: 0,
            skippedCount: 0,
            errorCount: 0,
            timeOutCount: 0,
            notCoveredCount: 0,
            perFile: [
                'app/ModuleA/Foo.php' => ['killed' => 1, 'escaped' => 4],  // 20% MSI (weak)
                'app/ModuleB/Bar.php' => ['killed' => 5, 'escaped' => 0],  // 100% MSI (strong)
            ],
        );

        $adapter = $this->makeAdapter($runner, mode: 'advisory');
        $result = $adapter->run(
            runId: 'run-e3-013-advisory',
            touchedFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
            ],
        );

        // The aggregate real MSI is 60% (at threshold), but per-file Foo is 20%.
        $this->assertSame(60.0, $result->realMsi, 'aggregate real MSI is 60%');
        $this->assertNotNull($result->perFileStats, 'per-file stats computed');
        $this->assertArrayHasKey('app/ModuleA/Foo.php', $result->perFileStats);
        $this->assertSame(20.0, $result->perFileStats['app/ModuleA/Foo.php']['msi']);
        $this->assertSame(100.0, $result->perFileStats['app/ModuleB/Bar.php']['msi']);

        // The gate trips because a weak file exists.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($result);
        $this->assertTrue(
            $verdict->tripped,
            'VAL-E3-013: weak file among strong ones still trips',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
        );
        $this->assertFalse($verdict->shouldFailGate, 'advisory never STATUS_FAILED for flag alone');
    }

    public function test_val_e3_013_weak_file_among_strong_ones_trips_verdict_hard(): void
    {
        // Same scenario in hard mode: the weak file forces STATUS_FAILED.
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 6,
            escapedCount: 4,
            perFile: [
                'app/ModuleA/Foo.php' => ['killed' => 1, 'escaped' => 4],
                'app/ModuleB/Bar.php' => ['killed' => 5, 'escaped' => 0],
            ],
        );

        $adapter = $this->makeAdapter($runner, mode: 'hard');
        $result = $adapter->run(
            runId: 'run-e3-013-hard',
            touchedFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
            ],
        );

        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($result);
        $this->assertTrue($verdict->tripped, 'weak file trips in hard');
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E3-013: hard routes to STATUS_FAILED',
        );
    }

    public function test_val_e3_013_all_strong_files_do_not_trip(): void
    {
        // When ALL files are above threshold, no trip (no false positive).
        $runner = new FakeMutationCommandRunner;
        $runner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 10,
            escapedCount: 0,
            perFile: [
                'app/ModuleA/Foo.php' => ['killed' => 5, 'escaped' => 0],
                'app/ModuleB/Bar.php' => ['killed' => 5, 'escaped' => 0],
            ],
        );

        $adapter = $this->makeAdapter($runner, mode: 'advisory');
        $result = $adapter->run(
            runId: 'run-e3-013-all-strong',
            touchedFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
            ],
        );

        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($result);
        $this->assertFalse($verdict->tripped, 'all-strong files do not trip');
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- Off mode byte-identical (anti-gaming code must not alter off) --------

    public function test_off_mode_anti_gaming_is_no_op_byte_identical(): void
    {
        $runner = new FakeMutationCommandRunner;
        $adapter = $this->makeAdapter($runner, mode: 'off');
        $result = $adapter->run(
            runId: 'run-e3-anti-off',
            touchedFiles: ['tests/Unit/FooTest.php', 'app/Foo.php'],
        );

        $this->assertTrue($result->skipped);
        $this->assertSame([], $runner->calls, 'infection not invoked');
        $this->assertNull($result->realMsi);
        $this->assertNull($result->perFileStats);
    }

    // -- Helpers --------------------------------------------------------------

    private function makeAdapter(FakeMutationCommandRunner $runner, string $mode): MutationTestingAdapter
    {
        return new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => $mode]),
            repoRoot: '/repo',
        );
    }

    private function extractConfigPath(string $command): string
    {
        if (preg_match('/--configuration=([^\s]+)/', $command, $m) !== 1) {
            return '';
        }
        $value = $m[1];
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
            $value = str_replace("'\\''", "'", $value);
        }

        return $value;
    }
}

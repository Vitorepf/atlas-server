<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Differential\Shadow;

use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarnessResult;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffResult;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffService;
use PHPUnit\Framework\TestCase;

/**
 * E4 -- ShadowDiffService unit tests.
 *
 * Drives the service with a fake harness so every branch is covered without
 * spawning real PHP subprocesses. The service is deterministic for the same
 * (workspace state, file list, harness): it reads OLD via `git show HEAD:`
 * and NEW from the workspace file, then asks the harness to execute both.
 *
 * Covers:
 *   - VAL-E4-005 (divergence on a pure modified symbol => divergent result).
 *   - VAL-E4-007 (impure body in old OR new => symbol skipped, no false flag).
 *   - VAL-E4-008 (agreement on all probes => agreement result, no flag).
 *   - VAL-E4-011 (newly-added symbol => skip with reason, no crash, no flag).
 *   - harness failure => skip with reason (honest ceiling, never fabricate).
 *   - no PHP files / no functions => skip with reason.
 */
final class ShadowDiffServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/atlas-shadow-svc-'.bin2hex(random_bytes(4));
        mkdir($this->workspace, 0o755, true);
        $this->initGit($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workspace);
        parent::tearDown();
    }

    // -- VAL-E4-005: pure-function divergence --------------------------------

    public function test_val_e4_005_pure_function_divergence_yields_divergent_result(): void
    {
        // OLD: function add(int $x, int $y): int { return $x + $y; }
        // NEW: function add(int $x, int $y): int { return $x * $y; }
        // The harness scripts OLD vs NEW outputs that diverge on (1,2).
        $this->commitOldVersion(
            'app/Math.php',
            "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y; }\n",
        );
        $this->writeNewVersion(
            'app/Math.php',
            "<?php\nfunction add(int \$x, int \$y): int { return \$x * \$y; }\n",
        );

        // The harness receives the wrapped callable source for old + new and
        // the probe inputs. For divergence we script old=[3,7,0,0],
        // new=[2,12,0,-1] (old=add, new=multiply over [(0,0),(1,2),(3,4)]).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(
            oldOutputs: ['0', '3', '7', '0'],
            newOutputs: ['0', '2', '12', '-1'],
        );

        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Math.php']);

        $this->assertTrue($result->diverged, 'VAL-E4-005: divergence on a pure modified symbol');
        $this->assertFalse($result->isSkipped, 'divergence is not a skip');
        $this->assertNotEmpty($result->divergentSymbols, 'divergent symbols carried');
        $this->assertSame('add', $result->divergentSymbols[0]['symbol']);
        $this->assertNotEmpty($result->divergentSymbols[0]['oldOutput']);
        $this->assertNotEquals(
            $result->divergentSymbols[0]['oldOutput'],
            $result->divergentSymbols[0]['newOutput'],
            'evidence carries differing old/new outputs',
        );
    }

    // -- VAL-E4-008: behavior-preserving refactor (agreement) ----------------

    public function test_val_e4_008_behavior_preserving_refactor_yields_agreement(): void
    {
        // OLD: function double(int $x): int { return $x + $x; }
        // NEW: function double(int $x): int { return $x * 2; }   (behavior-preserving)
        $this->commitOldVersion(
            'app/Math.php',
            "<?php\nfunction double(int \$x): int { return \$x + \$x; }\n",
        );
        $this->writeNewVersion(
            'app/Math.php',
            "<?php\nfunction double(int \$x): int { return \$x * 2; }\n",
        );

        // The harness scripts identical old/new outputs across all probes.
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(
            oldOutputs: ['0', '2', '-2', '84'],
            newOutputs: ['0', '2', '-2', '84'],
        );

        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Math.php']);

        $this->assertFalse($result->diverged, 'VAL-E4-008: agreement on a behavior-preserving refactor');
        $this->assertTrue($result->agreed, 'agreement result');
        $this->assertNotEmpty($result->evaluatedSymbols, 'evaluated symbol carried');
        $this->assertSame('double', $result->evaluatedSymbols[0]['symbol']);
        $this->assertSame([], $result->divergentSymbols, 'no divergent symbols');
    }

    // -- VAL-E4-007: impure code is conservatively skipped -------------------

    public function test_val_e4_007_impure_old_version_skips_symbol(): void
    {
        // OLD: function fetchValue(): int { return $_POST['x']; }  (superglobal)
        // NEW: function fetchValue(): int { return 42; }
        // The harness is NEVER called because the old body is impure.
        $this->commitOldVersion(
            'app/Math.php',
            "<?php\nfunction fetchValue(): int { return \$_POST['x']; }\n",
        );
        $this->writeNewVersion(
            'app/Math.php',
            "<?php\nfunction fetchValue(): int { return 42; }\n",
        );

        $harness = new FakeShadowDiffHarness;
        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Math.php']);

        $this->assertFalse($result->diverged, 'VAL-E4-007: impure old => no divergence flag');
        $this->assertTrue($result->isSkipped, 'impure => result is skipped');
        $this->assertSame(0, $harness->callCount, 'harness NEVER invoked on impure code');
        $this->assertNotEmpty($result->skipReason);
    }

    public function test_val_e4_007_impure_new_version_skips_symbol(): void
    {
        // OLD: function greet(string \$name): string { return 'Hi '.$name; }
        // NEW: function greet(string \$name): string { echo \$name; return ''; }  (echo)
        $this->commitOldVersion(
            'app/Math.php',
            "<?php\nfunction greet(string \$name): string { return 'Hi '.\$name; }\n",
        );
        $this->writeNewVersion(
            'app/Math.php',
            "<?php\nfunction greet(string \$name): string { echo \$name; return ''; }\n",
        );

        $harness = new FakeShadowDiffHarness;
        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Math.php']);

        $this->assertFalse($result->diverged, 'VAL-E4-007: impure new => no divergence flag');
        $this->assertSame(0, $harness->callCount, 'harness NEVER invoked when new is impure');
    }

    // -- VAL-E4-011: newly-added symbol skips with reason -------------------

    public function test_val_e4_011_newly_added_symbol_skips_with_reason_no_crash(): void
    {
        // No OLD version (file was empty at HEAD); NEW adds a pure function.
        $this->commitOldVersion('app/Math.php', "<?php\n");
        $this->writeNewVersion(
            'app/Math.php',
            "<?php\nfunction newlyAdded(int \$x): int { return \$x + 1; }\n",
        );

        $harness = new FakeShadowDiffHarness;
        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Math.php']);

        $this->assertFalse($result->diverged, 'VAL-E4-011: newly-added => no divergence');
        $this->assertFalse($result->agreed, 'newly-added => no agreement');
        $this->assertTrue($result->isSkipped, 'newly-added => result is skipped');
        $this->assertNotEmpty($result->skipReason, 'skip carries an explicit reason');
        $this->assertStringContainsString(
            'no prior implementation',
            $result->skipReason,
            'VAL-E4-011: reason mentions no baseline',
        );
        $this->assertSame(0, $harness->callCount, 'harness NEVER invoked on a newly-added symbol');
    }

    // -- Harness failure => skip with reason (honest ceiling) ----------------

    public function test_harness_failure_skips_symbol_with_reason_never_fabricates(): void
    {
        $this->commitOldVersion(
            'app/Math.php',
            "<?php\nfunction calculate(int \$x): int { return \$x; }\n",
        );
        $this->writeNewVersion(
            'app/Math.php',
            "<?php\nfunction calculate(int \$x): int { return \$x; }\n",
        );

        $harness = new FakeShadowDiffHarness;
        $harness->queueFailed('parse error: unexpected token');

        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Math.php']);

        $this->assertFalse($result->diverged, 'harness failure => no fabricated divergence');
        $this->assertTrue($result->isSkipped, 'harness failure => skipped');
        $this->assertStringContainsString('parse error', $result->skipReason);
    }

    // -- No PHP files / no functions => skip --------------------------------

    public function test_no_php_files_yields_skip(): void
    {
        $service = new ShadowDiffService(new FakeShadowDiffHarness);
        $result = $service->evaluate($this->workspace, ['app/README.md', 'app/style.css']);

        $this->assertTrue($result->isSkipped, 'non-PHP files => skip');
        $this->assertStringContainsString('no PHP files', $result->skipReason);
    }

    public function test_empty_file_list_yields_skip(): void
    {
        $service = new ShadowDiffService(new FakeShadowDiffHarness);
        $result = $service->evaluate($this->workspace, []);

        $this->assertTrue($result->isSkipped);
        $this->assertStringContainsString('no PHP files', $result->skipReason);
    }

    public function test_file_with_no_function_definitions_yields_skip(): void
    {
        $this->commitOldVersion('app/Config.php', "<?php\nconst FOO = 1;\n");
        $this->writeNewVersion('app/Config.php', "<?php\nconst FOO = 2;\n");

        $service = new ShadowDiffService(new FakeShadowDiffHarness);
        $result = $service->evaluate($this->workspace, ['app/Config.php']);

        $this->assertTrue($result->isSkipped);
        $this->assertStringContainsString('no function definitions', $result->skipReason);
    }

    // -- Mixed: one diverges, one agrees, one impure, one newly-added --------

    public function test_mixed_symbols_divergence_wins_over_agreement_and_skips(): void
    {
        // app/Calculator.php has three symbols:
        //   - add(int, int)        pure, MODIFIED, harness diverges
        //   - oldPureImpure(int)   OLD pure, NEW impure (skip)
        //   - brandNew(int)        newly-added (skip)
        $this->commitOldVersion(
            'app/Calculator.php',
            "<?php\n"
            ."function add(int \$a, int \$b): int { return \$a + \$b; }\n"
            ."function oldPureImpure(int \$a): int { return \$a; }\n",
        );
        $this->writeNewVersion(
            'app/Calculator.php',
            "<?php\n"
            ."function add(int \$a, int \$b): int { return \$a * \$b; }\n"
            ."function oldPureImpure(int \$a): int { echo \$a; return \$a; }\n"
            ."function brandNew(int \$a): int { return \$a + 1; }\n",
        );

        // The harness is called ONCE for `add` (the only pure MODIFIED symbol).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(oldOutputs: ['0'], newOutputs: ['1']);

        $service = new ShadowDiffService($harness);
        $result = $service->evaluate($this->workspace, ['app/Calculator.php']);

        $this->assertTrue($result->diverged, 'one diverging symbol => divergence');
        $this->assertCount(1, $result->divergentSymbols, 'only the pure modified diverging symbol is in the set');
        $this->assertSame('add', $result->divergentSymbols[0]['symbol']);
        $this->assertSame(1, $harness->callCount, 'harness invoked once for the pure modified symbol only');
        // The impure + newly-added symbols are in the skipped set.
        $skippedNames = array_column($result->skippedSymbols, 'symbol');
        $this->assertContains('oldPureImpure', $skippedNames);
        $this->assertContains('brandNew', $skippedNames);
    }

    // -- Helpers --------------------------------------------------------------

    private function commitOldVersion(string $relativePath, string $content): void
    {
        $absolute = $this->workspace.'/'.$relativePath;
        @mkdir(dirname($absolute), 0o755, true);
        file_put_contents($absolute, $content);
        $this->git($this->workspace, ['add', $relativePath]);
        $this->git($this->workspace, ['commit', '-m', 'old version', '--allow-empty']);
    }

    private function writeNewVersion(string $relativePath, string $content): void
    {
        $absolute = $this->workspace.'/'.$relativePath;
        @mkdir(dirname($absolute), 0o755, true);
        file_put_contents($absolute, $content);
    }

    private function initGit(string $workspace): void
    {
        $this->git($workspace, ['init', '-q']);
        $this->git($workspace, ['config', 'user.email', 'atlas-test@example.local']);
        $this->git($workspace, ['config', 'user.name', 'Atlas Test']);
    }

    private function git(string $workspace, array $args): void
    {
        $process = new \Symfony\Component\Process\Process(['git', ...$args], $workspace, null, null, 10.0);
        $process->run();
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Fake harness for unit tests. Queues scripted outputs so tests assert the
 * service's branch logic without spawning real PHP subprocesses.
 */
final class FakeShadowDiffHarness implements ShadowDiffHarness
{
    public int $callCount = 0;

    /** @var list<ShadowDiffHarnessResult> */
    private array $queue = [];

    public function queueExecuted(array $oldOutputs, array $newOutputs): void
    {
        $this->queue[] = ShadowDiffHarnessResult::executed($oldOutputs, $newOutputs);
    }

    public function queueFailed(string $reason): void
    {
        $this->queue[] = ShadowDiffHarnessResult::failed($reason);
    }

    public function shadowDiff(string $oldBodySource, string $newBodySource, array $probeInputs): ShadowDiffHarnessResult
    {
        $this->callCount++;

        return array_shift($this->queue) ?? ShadowDiffHarnessResult::executed([], []);
    }
}

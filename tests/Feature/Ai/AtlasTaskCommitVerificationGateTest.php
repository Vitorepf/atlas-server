<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fase 2: the server proves a worker's changes before committing. BLOCK only on a definitive failure
 * attributable to THIS task; FAIL OPEN on infra / unattributed breakage so a good worker is never bothered.
 */
final class AtlasTaskCommitVerificationGateTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /** A fake runner classifying by command verb, returning canned {ran,ok,out}. */
    private function runner(array $map): callable
    {
        return function (array $cmd, string $_cwd, float $_t) use ($map): array {
            $kind = in_array('-l', $cmd, true) ? 'lint' : (in_array('about', $cmd, true) ? 'boot' : (in_array('test', $cmd, true) ? 'test' : 'other'));

            return $map[$kind] ?? ['ran' => true, 'ok' => true, 'out' => ''];
        };
    }

    public function test_syntax_error_blocks(): void
    {
        // The gate only lints files that EXIST (a deletion is not a syntax concern), so the fixture file
        // must be present; the injected runner supplies the lint verdict.
        $repo = rtrim(sys_get_temp_dir(), '/').'/atlas-verify-syn-'.bin2hex(random_bytes(5));
        $this->dirs[] = $repo;
        File::ensureDirectoryExists($repo.'/app/Services/Ai/SelfConstruction');
        File::put($repo.'/app/Services/Ai/SelfConstruction/Foo.php', "<?php\nclass Foo {}\n");

        $gate = new AtlasTaskCommitVerificationGate($repo, $this->runner([
            'lint' => ['ran' => true, 'ok' => false, 'out' => 'PHP Parse error: syntax error'],
        ]));

        $out = $gate->verify(['app/Services/Ai/SelfConstruction/Foo.php'], 't1');

        $this->assertTrue($out['blocked']);
        $this->assertSame('syntax_error', $out['reason']);
    }

    public function test_clean_change_without_tests_passes(): void
    {
        $gate = new AtlasTaskCommitVerificationGate('/repo', $this->runner([
            'lint' => ['ran' => true, 'ok' => true, 'out' => 'No syntax errors'],
            'boot' => ['ran' => true, 'ok' => true, 'out' => 'env ok'],
        ]));

        $out = $gate->verify(['app/Services/Ai/SelfConstruction/Foo.php'], 't2');

        $this->assertTrue($out['passed']);
        $this->assertFalse($out['blocked']);
        $this->assertSame('pass', $out['checks']['boot']);
        $this->assertSame('skip', $out['checks']['task_tests']);
    }

    public function test_boot_failure_attributed_to_this_task_blocks(): void
    {
        $gate = new AtlasTaskCommitVerificationGate('/repo', $this->runner([
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => false, 'out' => 'Class "App\\Foo\\MissingDecorator" not found at app/Providers/AppServiceProvider.php:602'],
        ]));

        $out = $gate->verify(['app/Providers/AppServiceProvider.php'], 't3');

        $this->assertTrue($out['blocked'], 'boot fatal naming this task\'s file is attributed and blocked');
        $this->assertSame('bootstrap_failed', $out['reason']);
    }

    public function test_boot_failure_not_attributed_fails_open(): void
    {
        $gate = new AtlasTaskCommitVerificationGate('/repo', $this->runner([
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => false, 'out' => 'Class "App\\Other\\SiblingWip" not found at app/Other/Unrelated.php'],
        ]));

        $out = $gate->verify(['app/Services/Ai/SelfConstruction/Foo.php'], 't4');

        $this->assertTrue($out['passed'], 'a tree broken by someone ELSE must not punish this worker');
        $this->assertFalse($out['blocked']);
        $this->assertSame('fail_unattributed_open', $out['checks']['boot']);
    }

    public function test_red_task_test_blocks(): void
    {
        $gate = new AtlasTaskCommitVerificationGate('/repo', $this->runner([
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => false, 'out' => "FAILURES!\nTests: 3, Assertions: 5, Failures: 1."],
        ]));

        $out = $gate->verify([
            'app/Services/Ai/SelfConstruction/Foo.php',
            'tests/Unit/Ai/SelfConstruction/FooTest.php',
        ], 't5');

        $this->assertTrue($out['blocked']);
        $this->assertSame('task_tests_failed', $out['reason']);
    }

    /**
     * THE REGRESSION that wedged every worker: the runner exited NON-ZERO because of a bad option
     * ("Unknown option --without-tty"), not a red test. That is a RUNNER error — it must FAIL OPEN, never
     * block a good worker. (The old gate treated any non-zero exit as a test failure and stalled the fleet.)
     */
    public function test_runner_error_without_real_failure_marker_fails_open(): void
    {
        $gate = new AtlasTaskCommitVerificationGate('/repo', $this->runner([
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => false, 'out' => 'Unknown option "--without-tty". Most similar options are --no-output'],
        ]));

        $out = $gate->verify([
            'app/Services/Ai/SelfConstruction/Foo.php',
            'tests/Unit/Ai/SelfConstruction/FooTest.php',
        ], 't5b');

        $this->assertTrue($out['passed'], 'a runner/option error must NEVER block a worker — fail open');
        $this->assertFalse($out['blocked']);
        $this->assertSame('fail_open_runner_error', $out['checks']['task_tests']);
    }

    public function test_gate_infra_error_fails_open(): void
    {
        $gate = new AtlasTaskCommitVerificationGate('/repo', $this->runner([
            'lint' => ['ran' => false, 'ok' => true, 'out' => 'gate_infra_error'],
            'boot' => ['ran' => false, 'ok' => true, 'out' => 'gate_infra_error'],
        ]));

        $out = $gate->verify(['app/Services/Ai/SelfConstruction/Foo.php'], 't6');

        $this->assertTrue($out['passed'], 'if the gate itself cannot run, never block the worker');
    }

    public function test_real_php_lint_catches_a_real_syntax_error(): void
    {
        $repo = rtrim(sys_get_temp_dir(), '/').'/atlas-verify-'.bin2hex(random_bytes(5));
        $this->dirs[] = $repo;
        File::ensureDirectoryExists($repo.'/app');
        File::put($repo.'/app/Broken.php', "<?php\nclass Broken { public function x() { return ; }\n");

        // Real runner (no injection) — proves the Process-backed php -l path actually fires.
        $out = (new AtlasTaskCommitVerificationGate($repo))->verify(['app/Broken.php'], 'real');

        $this->assertTrue($out['blocked'], 'a real syntax error is caught by the real php -l path');
        $this->assertSame('syntax_error', $out['reason']);
    }

    public function test_enabled_by_default(): void
    {
        $this->assertTrue((new AtlasTaskCommitVerificationGate)->enabled());
    }

    /**
     * END-TO-END with the REAL runner (php -l + artisan about + artisan test) against a real, fast, stable
     * passing test — the coverage that was MISSING and let the `--without-tty` regression ship. It asserts the
     * task-test step actually EXECUTED and PASSED (checks=='pass'), so a future runner-arg regression (which
     * would fail OPEN, i.e. 'fail_open_runner_error') is caught here instead of in production.
     */
    public function test_real_artisan_test_path_executes_and_passes(): void
    {
        $target = 'tests/Unit/Ai/SelfConstruction/AtlasTaskPacketQualityInspectorTest.php';
        $this->assertFileExists(base_path($target), 'smoke target must exist');

        $out = (new AtlasTaskCommitVerificationGate(base_path()))->verify([$target], 'real-e2e');

        $this->assertTrue($out['passed'], 'a clean change with a real passing test must pass the gate: '.json_encode($out));
        $this->assertSame('pass', $out['checks']['task_tests'], 'the REAL artisan test executed and passed (not failed-open)');
    }
}

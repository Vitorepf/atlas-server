<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCharacterizationTestVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The acceptance keystone of the auto-characterization-test lane MUST refuse coverage theatre: only a
 * test that PASSES on correct code AND FAILS on the sampled mutant may certify. These tests pin that
 * with a runner that mirrors a REAL characterization test — it inspects the on-disk target so the
 * apply-mutant / restore flow is exercised, not just the verdict branching.
 */
final class AtlasLoopCharacterizationTestVerifierTest extends TestCase
{
    private string $dir;

    private string $rel = 'app/Subject.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-ctv-'.bin2hex(random_bytes(5));
        mkdir($this->dir.'/app', 0o755, true);
        // The "decision" the gate found uncovered is the `===`. Correct code uses ===; the strict_equals
        // mutant flips it to !==.
        file_put_contents($this->dir.'/'.$this->rel, "<?php\nfunction subject(\$a, \$b): int { return (\$a === \$b) ? 1 : 0; }\n");
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->dir);
        parent::tearDown();
    }

    /** A faithful characterization test: passes on `===`, fails on the `!==` mutant. */
    private function faithfulRunner(): callable
    {
        return function (string $workspace, string $command, int $timeout): array {
            $src = (string) file_get_contents($this->dir.'/'.$this->rel);
            // pins the === branch: "test" passes only while the correct operator is present
            return ['passed' => str_contains($src, '==='), 'exit_code' => str_contains($src, '===') ? 0 : 1, 'output' => ''];
        };
    }

    public function test_certifies_a_test_that_actually_kills_the_mutant(): void
    {
        $v = new AtlasLoopCharacterizationTestVerifier($this->faithfulRunner());
        $r = $v->verify($this->dir, $this->rel, 'tests/Unit/SubjectTest.php', 'strict_equals');

        $this->assertTrue($r['certified'], $r['reason']);
        $this->assertTrue($r['baseline_passed']);
        $this->assertTrue($r['mutant_killed']);
        // The mutant must NEVER be left on disk.
        $this->assertStringContainsString('===', (string) file_get_contents($this->dir.'/'.$this->rel));
        $this->assertStringNotContainsString('!==', (string) file_get_contents($this->dir.'/'.$this->rel));
    }

    public function test_rejects_useless_test_that_passes_on_both_correct_and_mutant(): void
    {
        // Coverage theatre: a test that always passes regardless of the operator.
        $v = new AtlasLoopCharacterizationTestVerifier(static fn (): array => ['passed' => true, 'exit_code' => 0, 'output' => '']);
        $r = $v->verify($this->dir, $this->rel, 'tests/Unit/SubjectTest.php', 'strict_equals');

        $this->assertFalse($r['certified']);
        $this->assertStringContainsString('mutant_survived', $r['reason']);
    }

    public function test_rejects_test_that_is_red_on_correct_code(): void
    {
        $v = new AtlasLoopCharacterizationTestVerifier(static fn (): array => ['passed' => false, 'exit_code' => 1, 'output' => '']);
        $r = $v->verify($this->dir, $this->rel, 'tests/Unit/SubjectTest.php', 'strict_equals');

        $this->assertFalse($r['certified']);
        $this->assertStringContainsString('baseline_red', $r['reason']);
        $this->assertFalse($r['baseline_passed']);
    }

    public function test_rejects_when_the_operator_cannot_reproduce_a_mutant(): void
    {
        // The target has no `>` so gt_comparison produces no mutant — cannot prove the test kills it.
        $v = new AtlasLoopCharacterizationTestVerifier(static fn (): array => ['passed' => true, 'exit_code' => 0, 'output' => '']);
        $r = $v->verify($this->dir, $this->rel, 'tests/Unit/SubjectTest.php', 'gt_comparison');

        $this->assertFalse($r['certified']);
        $this->assertStringContainsString('mutant_not_reproducible', $r['reason']);
    }

    public function test_missing_target_is_a_safe_rejection(): void
    {
        $v = new AtlasLoopCharacterizationTestVerifier(static fn (): array => ['passed' => true, 'exit_code' => 0, 'output' => '']);
        $r = $v->verify($this->dir, 'app/DoesNotExist.php', 'tests/Unit/SubjectTest.php', 'strict_equals');

        $this->assertFalse($r['certified']);
        $this->assertStringContainsString('target_missing', $r['reason']);
    }

    public function test_default_runner_forces_hermetic_testing_database_env(): void
    {
        mkdir($this->dir.'/vendor/bin', 0o755, true);
        mkdir($this->dir.'/tests/Unit', 0o755, true);
        file_put_contents($this->dir.'/vendor/bin/phpunit', <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__DIR__.'/../../env-seen.txt', getenv('APP_ENV').'|'.getenv('DB_CONNECTION').'|'.getenv('DB_DATABASE').'|'.getenv('DB_URL'));
$src = (string) file_get_contents(__DIR__.'/../../app/Subject.php');
exit(str_contains($src, '===') ? 0 : 1);
PHP);
        chmod($this->dir.'/vendor/bin/phpunit', 0o755);

        $oldAppEnv = getenv('APP_ENV');
        $oldDbConnection = getenv('DB_CONNECTION');
        $oldDbDatabase = getenv('DB_DATABASE');
        putenv('APP_ENV=local');
        putenv('DB_CONNECTION=pgsql');
        putenv('DB_DATABASE=atlas');

        try {
            $r = (new AtlasLoopCharacterizationTestVerifier)
                ->verify($this->dir, $this->rel, 'tests/Unit/SubjectTest.php', 'strict_equals');
        } finally {
            $this->restoreEnv('APP_ENV', $oldAppEnv);
            $this->restoreEnv('DB_CONNECTION', $oldDbConnection);
            $this->restoreEnv('DB_DATABASE', $oldDbDatabase);
        }

        $this->assertTrue($r['certified'], $r['reason']);
        $this->assertSame('testing|sqlite|:memory:|', (string) file_get_contents($this->dir.'/env-seen.txt'));
    }

    private function restoreEnv(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);

            return;
        }

        putenv($key.'='.$value);
    }

    private function rmDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($dir);
    }
}

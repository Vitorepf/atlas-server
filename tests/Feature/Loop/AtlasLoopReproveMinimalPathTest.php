<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pins the root-cause fix for the "scheduled drain merges 0 but manual merges
 * work" flakiness (health-check 13/06).
 *
 * The frozen judge re-proves a candidate by running its acceptance commands
 * (`php tests/atlas_generated_0.php`) through `/bin/sh -c "..."`. When the drain
 * is spawned by launchd — which gives the process a minimal PATH with no
 * `/opt/homebrew/bin` — `sh` cannot find `php` and the command exits 127. The
 * gate logged this as a transient `reproof_failed`, so the merge never happened
 * under the scheduler, yet a manual drain (interactive shell PATH has php)
 * always merged. NOT contention/timeout: a deterministic PATH/binary-resolution
 * bug. The fix threads `dirname(PHP_BINARY)` into the subprocess PATH so the
 * same interpreter resolves regardless of who spawned the judge.
 */
final class AtlasLoopReproveMinimalPathTest extends TestCase
{
    /** A directory guaranteed to contain no `php` — reproduces the launchd PATH. */
    private string $emptyPathDir = '';

    private ?string $originalPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH') === false ? null : (string) getenv('PATH');
        $this->emptyPathDir = sys_get_temp_dir().'/atlas_frozen_minimal_path_'.bin2hex(random_bytes(6));
        @mkdir($this->emptyPathDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->originalPath === null) {
            putenv('PATH');
        } else {
            putenv('PATH='.$this->originalPath);
        }
        if ($this->emptyPathDir !== '' && is_dir($this->emptyPathDir)) {
            @rmdir($this->emptyPathDir);
        }

        parent::tearDown();
    }

    public function test_frozen_command_env_prepends_interpreter_dir_to_existing_path(): void
    {
        putenv('PATH=/usr/bin:/bin');

        $env = $this->callFrozenCommandEnv();

        $this->assertIsArray($env);
        $this->assertArrayHasKey('PATH', $env);

        $binDir = \dirname(PHP_BINARY);
        $this->assertStringStartsWith($binDir.PATH_SEPARATOR, $env['PATH'], 'interpreter dir must be PREPENDED so it wins resolution');
        $this->assertStringContainsString('/usr/bin', $env['PATH'], 'the inherited PATH must be preserved, not replaced');
    }

    public function test_bare_php_command_fails_127_under_minimal_path(): void
    {
        // Documents the failure mode the launchd drain hit: with no php on PATH,
        // `sh -c "php ..."` exits 127 (command not found). Deterministic because
        // $emptyPathDir is the only PATH entry and contains no php.
        $process = Process::fromShellCommandline(
            'php -r \'echo "SHOULD_NOT_RUN";\'',
            null,
            ['PATH' => $this->emptyPathDir],
            null,
            30.0,
        );
        $process->run();

        $this->assertSame(127, $process->getExitCode(), 'bare php with no PATH must reproduce the exit-127 the drain logged');
    }

    public function test_frozen_command_resolves_php_under_minimal_path(): void
    {
        // Same minimal PATH that broke the launchd drain — now the judge must
        // still find php, because frozenCommandEnv() injects dirname(PHP_BINARY).
        putenv('PATH='.$this->emptyPathDir);

        $judge = app(AtlasEvolutionFrozenJudge::class);
        $method = new ReflectionMethod($judge, 'runFrozenCommand');
        $method->setAccessible(true);

        /** @var array{passed: bool, exit_code: int, stdout: string} $result */
        $result = $method->invoke($judge, 'php -r \'echo "FROZEN_OK";\'', $this->emptyPathDir, 30);

        $this->assertTrue($result['passed'], 'php-based acceptance command must pass even under a minimal launchd PATH');
        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('FROZEN_OK', $result['stdout']);
    }

    /**
     * @return array<string, string>|null
     */
    private function callFrozenCommandEnv(): ?array
    {
        $judge = app(AtlasEvolutionFrozenJudge::class);
        $method = new ReflectionMethod($judge, 'frozenCommandEnv');
        $method->setAccessible(true);

        /** @var array<string, string>|null $env */
        $env = $method->invoke($judge);

        return $env;
    }
}

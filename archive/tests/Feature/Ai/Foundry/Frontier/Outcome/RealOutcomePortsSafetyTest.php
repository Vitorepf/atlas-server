<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Foundry\Frontier\Outcome;

use App\Services\Ai\Foundry\Frontier\Outcome\GitRevertPort;
use App\Services\Ai\Foundry\Frontier\Outcome\MeasureCommandPort;
use App\Services\Ai\Foundry\Frontier\Outcome\RealGitRevertPort;
use App\Services\Ai\Foundry\Frontier\Outcome\RealMeasureCommandPort;
use Tests\TestCase;

/**
 * Foundry AP-E · safety of the LIVE real ports (invariant I5 Measured-or-Reverted).
 *
 * Proves the production RealMeasureCommandPort + RealGitRevertPort BLOCK honestly
 * with no real merged AFEF-origin finding (no FOUNDRY_AFEF_WORKTREE), refuse a
 * non-read-only measure command before spawning a process, and NEVER run a real
 * `git revert` / `git reset --hard` in this build. No real shell mutation occurs.
 */
final class RealOutcomePortsSafetyTest extends TestCase
{
    private string|false $savedWorktree;

    protected function setUp(): void
    {
        parent::setUp();
        // Guarantee no resolvable merged-finding worktree for the honest-block paths.
        $this->savedWorktree = getenv('FOUNDRY_AFEF_WORKTREE');
        putenv('FOUNDRY_AFEF_WORKTREE');
    }

    protected function tearDown(): void
    {
        if ($this->savedWorktree === false) {
            putenv('FOUNDRY_AFEF_WORKTREE');
        } else {
            putenv('FOUNDRY_AFEF_WORKTREE='.$this->savedWorktree);
        }
        parent::tearDown();
    }

    public function test_real_measure_port_implements_interface(): void
    {
        $this->assertInstanceOf(MeasureCommandPort::class, new RealMeasureCommandPort());
        $this->assertInstanceOf(GitRevertPort::class, new RealGitRevertPort());
    }

    public function test_measure_blocks_honestly_with_no_merged_finding(): void
    {
        $result = (new RealMeasureCommandPort())->run('php artisan test', 'loop.cycle_throughput');

        $this->assertFalse($result['ran']);
        $this->assertSame(-1, $result['exit_code']);
        $this->assertSame('', $result['stdout'], 'never fabricates a numeric when blocked');
        $this->assertSame('no_real_merged_finding', $result['stderr']);
    }

    public function test_measure_refuses_non_read_only_command_before_spawn(): void
    {
        $port = new RealMeasureCommandPort();
        foreach (['git revert HEAD', 'git reset --hard', 'rm -rf foo', 'echo x > out.txt'] as $cmd) {
            $result = $port->run($cmd, 'loop.cycle_throughput');
            $this->assertFalse($result['ran'], "must refuse to run: {$cmd}");
            $this->assertSame('', $result['stdout']);
        }
    }

    public function test_measure_blocks_on_empty_cmd_or_property(): void
    {
        $port = new RealMeasureCommandPort();
        $this->assertFalse($port->run('   ', 'prop')['ran']);
        $this->assertFalse($port->run('php artisan test', '  ')['ran']);
    }

    public function test_git_revert_blocks_honestly_and_never_runs_real_revert(): void
    {
        // Empty hash and no worktree both block; no real `git revert` ever executes.
        $port = new RealGitRevertPort();

        $empty = $port->revert('', 'php artisan test');
        $this->assertFalse($empty['reverted']);
        $this->assertSame('', $empty['revert_commit_hash']);
        $this->assertSame('no_real_merged_finding', $empty['detail']);

        $noWorktree = $port->revert('deadbeefcafe', 'php artisan test');
        $this->assertFalse($noWorktree['reverted']);
        $this->assertSame('no_real_merged_finding', $noWorktree['detail']);
        $this->assertFalse($noWorktree['verify_passed']);
    }
}

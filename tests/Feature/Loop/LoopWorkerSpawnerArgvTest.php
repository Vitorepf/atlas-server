<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerSpawner;
use Tests\TestCase;

/**
 * Runtime hardening of the grind worker, pinned so it cannot silently regress to a slow OOM/wedge:
 *  - memory_limit=2048M (a real grind exceeds PHP's 128M default and OOM-dies mid-grind → pool stalls)
 *  - pcov.enabled=0 (the dev php.ini loads pcov globally; its execute hook instruments every opcode of
 *    app/ and crawls the worker — a sampled wedged supervisor sat 100% inside php_pcov_execute_ex).
 * Both flags MUST precede `artisan` (php reads -d only before the script argument).
 */
final class LoopWorkerSpawnerArgvTest extends TestCase
{
    public function test_worker_argv_disables_pcov_and_raises_memory_before_artisan(): void
    {
        $argv = LoopWorkerSpawner::buildArgv('task-1', 'worker-1', 90, 0, '');

        $artisanIndex = array_search('artisan', $argv, true);
        $this->assertIsInt($artisanIndex, 'argv invokes artisan');
        $prefix = array_slice($argv, 0, $artisanIndex);

        $this->assertContains('memory_limit=2048M', $prefix, '2048M memory ceiling is set before the script');
        $this->assertContains('pcov.enabled=0', $prefix, 'pcov coverage hook is disabled before the script');
        $this->assertSame(PHP_BINARY, $argv[0], 'spawns the same php binary');
        $this->assertSame('atlas:loop:grind-task', $argv[$artisanIndex + 1], 'runs the grind-task command');
    }

    public function test_optional_flags_appended_only_when_present(): void
    {
        $bare = LoopWorkerSpawner::buildArgv('t', 'w', 60, 0, '');
        $this->assertNotContains('--scenarios=2', $bare);
        $this->assertFalse((bool) preg_grep('/^--workspace-root=/', $bare), 'no empty workspace-root flag');

        $full = LoopWorkerSpawner::buildArgv('t', 'w', 60, 2, '/tmp/ws');
        $this->assertContains('--scenarios=2', $full);
        $this->assertContains('--workspace-root=/tmp/ws', $full);
    }
}

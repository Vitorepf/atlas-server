<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelLockManager;
use Tests\TestCase;

final class AtlasAaelParallelLockManagerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-aael-locks-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_acquire_returns_handle_when_no_overlap(): void
    {
        $mgr = new AtlasAaelParallelLockManager($this->path);
        $h = $mgr->acquire('step-A', ['app/Foo.php']);
        $this->assertNotNull($h);
        $this->assertSame('step-A', $h->stepId);
        $this->assertSame(['app/Foo.php'], $h->writeSet);
    }

    public function test_acquire_refuses_overlapping_write_set(): void
    {
        $mgr = new AtlasAaelParallelLockManager($this->path);
        $first = $mgr->acquire('step-A', ['app/Foo.php', 'app/Bar.php']);
        $this->assertNotNull($first);

        $second = $mgr->acquire('step-B', ['app/Bar.php']);
        $this->assertNull($second, 'overlapping write_set must refuse');
    }

    public function test_directory_prefix_overlap_is_detected(): void
    {
        $mgr = new AtlasAaelParallelLockManager($this->path);
        $first = $mgr->acquire('step-A', ['app/Services/Foo']);
        $this->assertNotNull($first);

        $second = $mgr->acquire('step-B', ['app/Services/Foo/Bar.php']);
        $this->assertNull($second, 'sub-path under held directory must overlap');
    }

    public function test_release_lets_subsequent_acquire_succeed(): void
    {
        $mgr = new AtlasAaelParallelLockManager($this->path);
        $first = $mgr->acquire('step-A', ['app/Foo.php']);
        $this->assertNotNull($first);

        $blocked = $mgr->acquire('step-B', ['app/Foo.php']);
        $this->assertNull($blocked);

        $mgr->release($first);
        $newHandle = $mgr->acquire('step-C', ['app/Foo.php']);
        $this->assertNotNull($newHandle);
    }

    public function test_empty_step_id_or_empty_write_set_returns_null(): void
    {
        $mgr = new AtlasAaelParallelLockManager($this->path);
        $this->assertNull($mgr->acquire('', ['app/Foo.php']));
        $this->assertNull($mgr->acquire('step', []));
    }

    public function test_malformed_ledger_is_fail_closed(): void
    {
        file_put_contents($this->path, 'not-json');
        $mgr = new AtlasAaelParallelLockManager($this->path);
        $this->assertNull($mgr->acquire('step-A', ['app/Foo.php']));
    }
}

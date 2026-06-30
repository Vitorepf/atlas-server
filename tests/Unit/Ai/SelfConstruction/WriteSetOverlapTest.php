<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\WriteSetOverlap;
use Tests\TestCase;

/**
 * PART 2 · A4/A5 (MF-07/MF-12) — the single conflict predicate. Pins the two holes the old `array_intersect`
 * had: dir-vs-file (never matched a bare directory) and read-vs-write (only compared write∩write).
 */
final class WriteSetOverlapTest extends TestCase
{
    public function test_paths_collide_directory_versus_file(): void
    {
        $this->assertTrue(WriteSetOverlap::pathsCollide('app/Foo', 'app/Foo/Bar.php'));
        $this->assertTrue(WriteSetOverlap::pathsCollide('app/Foo/', 'app/Foo/Bar.php'));
        $this->assertTrue(WriteSetOverlap::pathsCollide('app/Foo/Bar.php', 'app/Foo/Bar.php'));
    }

    public function test_paths_do_not_collide_on_a_non_boundary_prefix(): void
    {
        // 'app/Foo' must NOT match 'app/FooBar.php' (the '/' boundary prevents the substring false-positive).
        $this->assertFalse(WriteSetOverlap::pathsCollide('app/Foo', 'app/FooBar.php'));
        $this->assertFalse(WriteSetOverlap::pathsCollide('app/A.php', 'app/B.php'));
    }

    public function test_conflicts_cover_write_write_write_read_read_write_but_not_read_read(): void
    {
        // write ∩ write
        $this->assertSame(['a.php'], WriteSetOverlap::conflicts(['a.php'], [], ['a.php'], []));
        // A writes what B reads
        $this->assertSame(['a.php'], WriteSetOverlap::conflicts(['a.php'], [], [], ['a.php']));
        // A reads what B writes
        $this->assertSame(['a.php'], WriteSetOverlap::conflicts([], ['a.php'], ['a.php'], []));
        // read ∩ read is SAFE — never a conflict
        $this->assertSame([], WriteSetOverlap::conflicts([], ['a.php'], [], ['a.php']));
    }

    public function test_conflicts_are_dir_vs_file_aware(): void
    {
        $this->assertSame(['app/Foo'], WriteSetOverlap::conflicts(['app/Foo'], [], ['app/Foo/Bar.php'], []));
        $this->assertSame([], WriteSetOverlap::conflicts(['app/Foo'], [], ['app/Bar/Baz.php'], []));
    }

    public function test_canonical_slash_normalization_detects_collision(): void
    {
        $this->assertTrue(WriteSetOverlap::pathsCollide('app//Foo', 'app/Foo/Bar.php'));
        $this->assertTrue(WriteSetOverlap::pathsCollide('app\\Foo', 'app/Foo/Bar.php'));
        $this->assertSame(['app/Foo'], WriteSetOverlap::conflicts(['app//Foo'], [], ['app/Foo/Bar.php'], []));
        $this->assertSame(['app/Foo'], WriteSetOverlap::conflicts(['app\\Foo'], [], ['app/Foo/Bar.php'], []));
    }

    public function test_traversal_paths_are_always_unsafe_collision(): void
    {
        $this->assertTrue(WriteSetOverlap::pathsCollide('../etc/passwd', 'etc/passwd'));
        $this->assertTrue(WriteSetOverlap::pathsCollide('app/../etc', 'etc/passwd'));
        $this->assertNotEmpty(WriteSetOverlap::conflicts(['../etc/passwd'], [], ['app/Foo.php'], []));
        $this->assertNotEmpty(WriteSetOverlap::conflicts(['app/Safe.php'], [], ['app/../secret'], []));
    }

    public function test_empty_paths_in_write_sets_produce_no_collision(): void
    {
        $this->assertFalse(WriteSetOverlap::pathsCollide('', 'app/Foo.php'));
        $this->assertFalse(WriteSetOverlap::pathsCollide('app/Foo.php', ''));
        $this->assertSame([], WriteSetOverlap::conflicts([''], [], ['app/Foo.php'], []));
        $this->assertSame([], WriteSetOverlap::conflicts(['app/Foo.php'], [], [''], []));
    }
}

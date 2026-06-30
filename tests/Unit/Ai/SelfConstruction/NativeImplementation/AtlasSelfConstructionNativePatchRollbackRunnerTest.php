<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchRollbackRunner;
use Tests\TestCase;

final class AtlasSelfConstructionNativePatchRollbackRunnerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-rollback-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function writeFile(string $path, string $contents): void
    {
        $abs = $this->root.'/'.$path;
        @mkdir(\dirname($abs), 0o755, true);
        file_put_contents($abs, $contents);
    }

    public function test_successful_rollback_restores_preimage_for_modified_file(): void
    {
        // After apply: file has 'new'. Rollback restores preimage 'old'.
        $this->writeFile('app/Foo.php', "new\n");
        $postHash = hash_file('sha256', $this->root.'/app/Foo.php');

        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Foo.php'],
            'receipts' => [[
                'path' => 'app/Foo.php',
                'mode' => 'modify',
                'preimage_contents' => "old\n",
                'preimage_hash' => hash('sha256', "old\n"),
                'post_hash' => $postHash,
            ]],
        ]);

        $this->assertFalse($verdict['refused']);
        $this->assertSame("old\n", file_get_contents($this->root.'/app/Foo.php'));
        $this->assertSame('app/Foo.php', $verdict['restored'][0]['path']);
    }

    public function test_hash_mismatch_refuses_rollback(): void
    {
        $this->writeFile('app/Foo.php', "actual\n");

        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Foo.php'],
            'receipts' => [[
                'path' => 'app/Foo.php',
                'mode' => 'modify',
                'preimage_contents' => "old\n",
                'preimage_hash' => hash('sha256', "old\n"),
                'post_hash' => 'WRONG_HASH',
            ]],
        ]);

        $this->assertTrue($verdict['refused']);
        $this->assertContains('post_hash_mismatch:app/Foo.php', $verdict['blockers']);
        $this->assertSame("actual\n", file_get_contents($this->root.'/app/Foo.php'), 'file must be unchanged when rollback refused');
    }

    public function test_new_file_removal_when_apply_was_a_create(): void
    {
        // After apply: a NEW file was created. Rollback should delete it.
        $this->writeFile('app/Brand.php', "created\n");
        $postHash = hash_file('sha256', $this->root.'/app/Brand.php');

        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Brand.php'],
            'receipts' => [[
                'path' => 'app/Brand.php',
                'mode' => 'create',
                'post_hash' => $postHash,
            ]],
        ]);

        $this->assertFalse($verdict['refused']);
        $this->assertSame(['app/Brand.php'], $verdict['removed']);
        $this->assertFileDoesNotExist($this->root.'/app/Brand.php');
    }

    public function test_deleted_file_restoration_when_apply_removed_a_file(): void
    {
        // After apply: file no longer exists (apply removed it). Rollback restores preimage contents.
        // No on-disk file; post_hash='' signals "deleted-by-apply".
        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Deleted.php'],
            'receipts' => [[
                'path' => 'app/Deleted.php',
                'mode' => 'modify',
                'preimage_contents' => "restored\n",
                'preimage_hash' => hash('sha256', "restored\n"),
                'post_hash' => '',
            ]],
        ]);

        $this->assertFalse($verdict['refused']);
        $this->assertSame("restored\n", file_get_contents($this->root.'/app/Deleted.php'));
    }

    public function test_outside_allowed_files_is_refused(): void
    {
        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Foo.php'],
            'receipts' => [[
                'path' => 'lib/Bar.php',
                'mode' => 'modify',
                'preimage_contents' => 'x',
                'preimage_hash' => hash('sha256', 'x'),
                'post_hash' => '',
            ]],
        ]);

        $this->assertTrue($verdict['refused']);
        $this->assertContains('outside_allowed_files:lib/Bar.php', $verdict['blockers']);
    }

    public function test_traversal_path_refuses_rollback(): void
    {
        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['../etc/passwd'],
            'receipts' => [[
                'path' => '../etc/passwd',
                'mode' => 'modify',
                'preimage_contents' => 'x',
                'preimage_hash' => hash('sha256', 'x'),
                'post_hash' => '',
            ]],
        ]);

        $this->assertTrue($verdict['refused']);
        $this->assertContains('path_traversal_or_empty:../etc/passwd', $verdict['blockers']);
    }

    public function test_unknown_mode_refuses_rollback_before_touching_disk(): void
    {
        $this->writeFile('app/Foo.php', "existing\n");
        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Foo.php'],
            'receipts' => [[
                'path' => 'app/Foo.php',
                'mode' => 'purge',
                'post_hash' => '',
            ]],
        ]);

        $this->assertTrue($verdict['refused']);
        $this->assertContains('unknown_mode:purge', $verdict['blockers']);
        $this->assertSame("existing\n", file_get_contents($this->root.'/app/Foo.php'), 'file must be untouched');
    }

    public function test_duplicate_receipt_path_refuses_rollback_before_touching_disk(): void
    {
        $this->writeFile('app/Foo.php', "original\n");
        $postHash = hash_file('sha256', $this->root.'/app/Foo.php');
        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Foo.php'],
            'receipts' => [
                ['path' => 'app/Foo.php', 'mode' => 'modify', 'preimage_contents' => "v1\n", 'preimage_hash' => hash('sha256', "v1\n"), 'post_hash' => $postHash],
                ['path' => 'app/Foo.php', 'mode' => 'modify', 'preimage_contents' => "v2\n", 'preimage_hash' => hash('sha256', "v2\n"), 'post_hash' => $postHash],
            ],
        ]);

        $this->assertTrue($verdict['refused']);
        $this->assertContains('duplicate_receipt_path:app/Foo.php', $verdict['blockers']);
        $this->assertSame("original\n", file_get_contents($this->root.'/app/Foo.php'), 'file must be untouched');
    }

    public function test_modify_without_preimage_hash_refuses_rollback(): void
    {
        $runner = new AtlasSelfConstructionNativePatchRollbackRunner($this->root);
        $verdict = $runner->rollback([
            'allowed_files' => ['app/Foo.php'],
            'receipts' => [[
                'path' => 'app/Foo.php',
                'mode' => 'modify',
                'preimage_contents' => "old\n",
                'post_hash' => '',
            ]],
        ]);

        $this->assertTrue($verdict['refused']);
        $this->assertContains('modify_missing_preimage_hash:app/Foo.php', $verdict['blockers']);
    }
}

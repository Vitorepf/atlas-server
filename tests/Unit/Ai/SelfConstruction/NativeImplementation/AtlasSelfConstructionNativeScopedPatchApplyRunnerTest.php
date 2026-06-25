<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeScopedPatchApplyRunner;
use Tests\TestCase;

final class AtlasSelfConstructionNativeScopedPatchApplyRunnerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-scoped-apply-'.bin2hex(random_bytes(6));
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

    public function test_create_writes_new_file_atomically(): void
    {
        $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner($this->root);
        $verdict = $runner->apply(
            ['decision' => 'allow'],
            [
                'allowed_files' => ['app/Foo.php'],
                'files' => [['path' => 'app/Foo.php', 'contents' => "hello\n", 'mode' => 'create']],
            ],
        );

        $this->assertFalse($verdict['refused']);
        $this->assertSame('app/Foo.php', $verdict['applied'][0]['path']);
        $this->assertSame("hello\n", file_get_contents($this->root.'/app/Foo.php'));
    }

    public function test_modify_writes_when_preimage_matches(): void
    {
        @mkdir($this->root.'/app', 0o755, true);
        file_put_contents($this->root.'/app/Foo.php', "old\n");
        $expected = hash('sha256', "old\n");

        $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner($this->root);
        $verdict = $runner->apply(
            ['decision' => 'allow'],
            [
                'allowed_files' => ['app/Foo.php'],
                'files' => [['path' => 'app/Foo.php', 'contents' => "new\n", 'mode' => 'modify', 'expected_preimage_hash' => $expected]],
            ],
        );

        $this->assertFalse($verdict['refused']);
        $this->assertSame("new\n", file_get_contents($this->root.'/app/Foo.php'));
    }

    public function test_drift_in_preimage_hash_refuses_modify(): void
    {
        @mkdir($this->root.'/app', 0o755, true);
        file_put_contents($this->root.'/app/Foo.php', "real\n");

        $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner($this->root);
        $verdict = $runner->apply(
            ['decision' => 'allow'],
            [
                'allowed_files' => ['app/Foo.php'],
                'files' => [['path' => 'app/Foo.php', 'contents' => "next\n", 'mode' => 'modify', 'expected_preimage_hash' => 'wrong_hash']],
            ],
        );

        $this->assertTrue($verdict['refused']);
        $this->assertContains('preimage_drift:app/Foo.php', $verdict['blockers']);
        $this->assertSame("real\n", file_get_contents($this->root.'/app/Foo.php'), 'on-disk file must be unchanged on drift');
    }

    public function test_outside_scope_path_is_refused(): void
    {
        $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner($this->root);
        $verdict = $runner->apply(
            ['decision' => 'allow'],
            [
                'allowed_files' => ['app/Foo.php'],
                'files' => [['path' => 'lib/Bar.php', 'contents' => 'x', 'mode' => 'create']],
            ],
        );

        $this->assertTrue($verdict['refused']);
        $this->assertContains('outside_allowed_files:lib/Bar.php', $verdict['blockers']);
    }

    public function test_path_traversal_is_refused(): void
    {
        $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner($this->root);
        $verdict = $runner->apply(
            ['decision' => 'allow'],
            [
                'allowed_files' => ['../etc/passwd'],
                'files' => [['path' => '../etc/passwd', 'contents' => 'x', 'mode' => 'create']],
            ],
        );

        $this->assertTrue($verdict['refused']);
        $this->assertContains('path_traversal_or_empty:../etc/passwd', $verdict['blockers']);
    }

    public function test_preflight_not_allow_refuses(): void
    {
        $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner($this->root);
        $verdict = $runner->apply(
            ['decision' => 'reject'],
            ['allowed_files' => ['x'], 'files' => [['path' => 'x', 'contents' => 'y']]],
        );

        $this->assertTrue($verdict['refused']);
        $this->assertContains('preflight_not_allow:reject', $verdict['blockers']);
    }

    public function test_runner_does_not_call_git_or_provider(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionNativeScopedPatchApplyRunner.php'));
        foreach (['git ', 'shell_exec', 'exec(', 'system(', 'proc_open', 'curl_', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "runner source must NOT contain {$forbidden}");
        }
    }
}

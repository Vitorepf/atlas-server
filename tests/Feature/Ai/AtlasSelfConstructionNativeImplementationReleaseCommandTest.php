<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:native-implementation-release covers preflight / apply / verify /
 * rollback through a temp project root and never runs git or calls providers.
 */
final class AtlasSelfConstructionNativeImplementationReleaseCommandTest extends TestCase
{
    private string $payloadPath = '';

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->payloadPath = sys_get_temp_dir().'/atlas_release_'.bin2hex(random_bytes(6)).'.json';
        $this->root = sys_get_temp_dir().'/atlas-release-root-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->payloadPath);
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

    private function writeJson(array $data): void
    {
        file_put_contents($this->payloadPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_preflight_returns_decision_facts(): void
    {
        $this->writeJson([
            'proposal' => [
                'changed_files' => ['app/Foo.php'],
                'allowed_files' => ['app/Foo.php'],
                'forbidden_targets' => [],
                'required_evidence' => ['phpunit'],
                'evidence_refs' => ['phpunit:run-1'],
                'rollback_preimage' => ['app/Foo.php' => 'old_hash'],
                'merge_governor' => [],
            ],
        ]);
        Artisan::call('atlas:self-construction:native-implementation-release', ['action' => 'preflight', '--payload' => $this->payloadPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertArrayHasKey('decision', $p['preflight']);
    }

    public function test_apply_writes_file_to_temp_project_root(): void
    {
        $this->writeJson([
            'project_root' => $this->root,
            'preflight' => ['decision' => 'allow'],
            'proposal' => [
                'allowed_files' => ['app/Foo.php'],
                'files' => [['path' => 'app/Foo.php', 'contents' => "hello\n", 'mode' => 'create']],
            ],
        ]);
        Artisan::call('atlas:self-construction:native-implementation-release', ['action' => 'apply', '--payload' => $this->payloadPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertFalse($p['apply']['refused']);
        $this->assertSame("hello\n", file_get_contents($this->root.'/app/Foo.php'));
    }

    public function test_verify_returns_failed_when_a_gate_failed(): void
    {
        $this->writeJson([
            'facts' => [
                'task_packet_id' => 'pkt-1',
                'expected_gates' => ['phpunit'],
                'apply_receipts' => [['path' => 'app/Foo.php', 'post_hash' => 'h1']],
                'changed_files' => [['path' => 'app/Foo.php', 'post_hash' => 'h1']],
                'gate_results' => [['gate' => 'phpunit', 'exit_code' => 3]],
            ],
        ]);
        Artisan::call('atlas:self-construction:native-implementation-release', ['action' => 'verify', '--payload' => $this->payloadPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame('failed', $p['verify']['verdict']);
    }

    public function test_rollback_restores_preimage_in_temp_project_root(): void
    {
        @mkdir($this->root.'/app', 0o755, true);
        file_put_contents($this->root.'/app/Foo.php', "new\n");
        $postHash = hash_file('sha256', $this->root.'/app/Foo.php');

        $this->writeJson([
            'project_root' => $this->root,
            'facts' => [
                'allowed_files' => ['app/Foo.php'],
                'receipts' => [[
                    'path' => 'app/Foo.php',
                    'mode' => 'modify',
                    'preimage_contents' => "old\n",
                    'preimage_hash' => hash('sha256', "old\n"),
                    'post_hash' => $postHash,
                ]],
            ],
        ]);
        Artisan::call('atlas:self-construction:native-implementation-release', ['action' => 'rollback', '--payload' => $this->payloadPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertFalse($p['rollback']['refused']);
        $this->assertSame("old\n", file_get_contents($this->root.'/app/Foo.php'));
    }

    public function test_malformed_payload_yields_usage_error(): void
    {
        $this->writeJson(['unknown' => true]);
        $exit = Artisan::call('atlas:self-construction:native-implementation-release', ['action' => 'preflight', '--payload' => $this->payloadPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_command_source_does_not_call_git_or_provider(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionNativeImplementationReleaseCommand.php'));
        foreach (['git ', 'shell_exec', 'exec(', 'system(', 'proc_open', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "release command source must NOT contain {$forbidden}");
        }
    }
}

<?php

namespace Tests\Unit;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiToolRuntimeTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-tool-runtime-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_file_write_dry_run_returns_diff_without_writing(): void
    {
        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('file.write', $this->workspace, [
            'path' => 'notes/a.txt',
            'content' => 'Atlas',
        ], [
            'permission_mode' => 'write',
            'dry_run' => true,
        ]));

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('+Atlas', (string) $result->diff);
        $this->assertFalse(File::exists($this->workspace.'/notes/a.txt'));
    }

    public function test_file_write_creates_checkpoint_and_file_when_approved(): void
    {
        File::put($this->workspace.'/a.txt', 'antes');

        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('file.write', $this->workspace, [
            'path' => 'a.txt',
            'content' => 'depois',
        ], [
            'permission_mode' => 'write',
            'metadata' => [
                'approved' => true,
                'approval_source' => 'test',
            ],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame('depois', File::get($this->workspace.'/a.txt'));
        $this->assertNotNull($result->checkpointPath);
        $this->assertTrue(File::exists($result->checkpointPath.'/a.txt'));
    }

    public function test_file_patch_replaces_first_match_when_approved(): void
    {
        File::put($this->workspace.'/a.txt', "um\num\n");

        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('file.patch', $this->workspace, [
            'path' => 'a.txt',
            'search' => 'um',
            'replace' => 'dois',
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame("dois\num\n", File::get($this->workspace.'/a.txt'));
    }

    public function test_checkpoint_restore_rolls_back_previous_write(): void
    {
        File::put($this->workspace.'/a.txt', 'antes');
        $runtime = app(AiToolRuntime::class);

        $write = $runtime->execute(ToolInvocation::make('file.write', $this->workspace, [
            'path' => 'a.txt',
            'content' => 'depois',
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $restore = $runtime->execute(ToolInvocation::make('checkpoint.restore', $this->workspace, [
            'checkpoint' => $write->checkpointPath,
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $this->assertTrue($restore->ok);
        $this->assertSame('antes', File::get($this->workspace.'/a.txt'));
    }

    public function test_test_run_uses_isolated_testing_environment(): void
    {
        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('test.run', $this->workspace, [
            'command' => 'php -r "exit(getenv(\'APP_ENV\') === \'testing\' && getenv(\'DB_CONNECTION\') === \'sqlite\' ? 0 : 1);"',
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $this->assertTrue($result->ok);
    }

    public function test_failed_test_run_writes_failure_artifact(): void
    {
        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('test.run', $this->workspace, [
            'command' => PHP_BINARY.' -r "fwrite(STDOUT, \'PASS before failure\'.PHP_EOL); fwrite(STDERR, \'FAILED final cause\'.PHP_EOL); exit(1);"',
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $artifactPath = data_get($result->metadata, 'artifact_path');

        $this->assertFalse($result->ok);
        $this->assertIsString($artifactPath);
        $this->assertTrue(File::exists($artifactPath));
        $this->assertStringContainsString('PASS before failure', File::get($artifactPath));
        $this->assertStringContainsString('FAILED final cause', File::get($artifactPath));
    }

    public function test_file_read_redacts_secrets_before_returning_tool_output(): void
    {
        File::put($this->workspace.'/.env', "OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123456\npassword=super-secret-value\n");

        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('file.read', $this->workspace, [
            'path' => '.env',
        ]));

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('[redacted]', $result->output);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz123456', $result->output);
        $this->assertStringNotContainsString('super-secret-value', $result->output);
    }

    public function test_shell_run_uses_filtered_tool_environment(): void
    {
        putenv('OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123456');
        $_ENV['OPENAI_API_KEY'] = 'sk-proj-abcdefghijklmnopqrstuvwxyz123456';
        $_SERVER['OPENAI_API_KEY'] = 'sk-proj-abcdefghijklmnopqrstuvwxyz123456';

        try {
            $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('shell.run', $this->workspace, [
                'command' => PHP_BINARY.' -r "echo getenv(\'OPENAI_API_KEY\') ?: \'missing\';"',
            ], [
                'permission_mode' => 'read',
            ]));
        } finally {
            putenv('OPENAI_API_KEY');
            unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
        }

        $this->assertTrue($result->ok);
        $this->assertSame('missing', trim($result->stdout));
    }

    public function test_programming_test_dry_run_returns_action_runtime_contract_without_execution(): void
    {
        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('programming.test', $this->workspace, [
            'command' => PHP_BINARY.' -r "file_put_contents(\'ran.txt\', \'yes\');"',
        ], [
            'permission_mode' => 'write',
            'dry_run' => true,
        ]));

        $this->assertTrue($result->ok);
        $this->assertStringContainsString(PHP_BINARY, $result->output);
        $this->assertFalse(File::exists($this->workspace.'/ran.txt'));
        $this->assertSame('atlas.tool_action_runtime.contract.v1', data_get($result->metadata, 'action_runtime_contract.schema_version'));
        $this->assertSame('programming.test', data_get($result->metadata, 'action_runtime_contract.tool'));
        $this->assertSame('test', data_get($result->metadata, 'action_runtime_contract.programming_action'));
        $this->assertTrue((bool) data_get($result->metadata, 'action_runtime_contract.dry_run'));
        $this->assertFalse((bool) data_get($result->metadata, 'action_runtime_contract.provider_dispatch_allowed'));
        $this->assertFalse((bool) data_get($result->metadata, 'action_runtime_contract.raw_command_exposed'));
    }

    public function test_programming_lint_executes_declared_command_with_evidence_contract(): void
    {
        $result = app(AiToolRuntime::class)->execute(ToolInvocation::make('programming.lint', $this->workspace, [
            'command' => PHP_BINARY.' -r "echo \'lint-ok\';"',
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame('lint-ok', trim($result->stdout));
        $this->assertSame('lint', data_get($result->metadata, 'action_runtime_contract.programming_action'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($result->metadata, 'action_runtime_contract.evidence.stdout_sha256'));
        $this->assertFalse((bool) data_get($result->metadata, 'action_runtime_contract.rollback.available'));
    }

    public function test_programming_read_actions_are_canonical_aliases_for_diff_and_code_search(): void
    {
        File::put($this->workspace.'/a.php', "<?php\n// Atlas Needle\n");

        $search = app(AiToolRuntime::class)->execute(ToolInvocation::make('programming.code_search', $this->workspace, [
            'query' => 'Atlas Needle',
        ]));
        $diff = app(AiToolRuntime::class)->execute(ToolInvocation::make('programming.git_diff', $this->workspace));

        $this->assertTrue($search->ok);
        $this->assertStringContainsString('a.php', $search->stdout);
        $this->assertSame('code_search', data_get($search->metadata, 'action_runtime_contract.programming_action'));
        $this->assertTrue($diff->ok);
        $this->assertSame('git_diff', data_get($diff->metadata, 'action_runtime_contract.programming_action'));
        $this->assertSame('read', data_get($diff->metadata, 'action_runtime_contract.permission_mode'));
    }

    public function test_programming_quality_and_visual_smoke_have_safe_dry_run_contracts(): void
    {
        $quality = app(AiToolRuntime::class)->execute(ToolInvocation::make('programming.quality_scan', $this->workspace, [
            'profile' => 'fast',
            'timeout' => 10,
        ], [
            'permission_mode' => 'write',
            'dry_run' => true,
        ]));
        $visual = app(AiToolRuntime::class)->execute(ToolInvocation::make('programming.visual_smoke', $this->workspace, [
            'url' => 'http://127.0.0.1:3000',
            'routes' => ['/health'],
        ], [
            'permission_mode' => 'write',
            'dry_run' => true,
        ]));

        $this->assertTrue($quality->ok);
        $this->assertStringContainsString('atlas:engineering:quality-scan', $quality->output);
        $this->assertSame('quality_scan', data_get($quality->metadata, 'action_runtime_contract.programming_action'));
        $this->assertTrue($visual->ok);
        $this->assertStringContainsString('atlas:engineering:visual-smoke', $visual->output);
        $this->assertStringContainsString('--route=/health', $visual->output);
        $this->assertSame('visual_smoke', data_get($visual->metadata, 'action_runtime_contract.programming_action'));
        $this->assertSame('atlas.programming.action_manifest.v1', data_get($visual->metadata, 'programming_action_manifest.schema_version'));
        $this->assertSame('programming.visual_smoke', data_get($visual->metadata, 'programming_action_manifest.tool'));
        $this->assertSame('test', data_get($visual->metadata, 'programming_action_manifest.stage'));
        $this->assertSame('advisory', data_get($visual->metadata, 'programming_action_manifest.gate_effect'));
    }
}

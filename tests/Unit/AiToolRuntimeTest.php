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
}

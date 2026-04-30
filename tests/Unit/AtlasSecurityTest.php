<?php

namespace Tests\Unit;

use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AtlasSecurityTest extends TestCase
{
    private string $workspace;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-security-test-'.bin2hex(random_bytes(4));
        $this->outside = sys_get_temp_dir().'/atlas-security-outside-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->outside);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::deleteDirectory($this->outside);

        parent::tearDown();
    }

    public function test_redacts_common_secret_shapes_recursively(): void
    {
        $payload = AtlasSecurity::redactArray([
            'OPENAI_API_KEY' => 'sk-proj-abcdefghijklmnopqrstuvwxyz123456',
            'session_id' => 'session-visible-for-cli-continuity',
            'nested' => [
                'line' => 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz',
                'dsn' => 'password=super-secret-value',
            ],
        ]);

        $encoded = json_encode($payload);

        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz123456', $encoded);
        $this->assertStringNotContainsString('super-secret-value', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
        $this->assertStringContainsString('session-visible-for-cli-continuity', $encoded);
    }

    public function test_command_display_uses_shell_safe_quoting_and_redaction(): void
    {
        $display = AtlasSecurity::commandLineForDisplay([
            'php',
            '-r',
            'echo "token=secret-value";',
        ]);

        $this->assertSame("php -r 'echo \"token=[redacted]\";'", $display);
    }

    public function test_existing_symlink_that_points_outside_workspace_is_not_inside(): void
    {
        File::put($this->outside.'/secret.txt', 'secret');
        symlink($this->outside.'/secret.txt', $this->workspace.'/linked-secret.txt');

        $this->assertFalse(AtlasSecurity::pathIsInside($this->workspace.'/linked-secret.txt', $this->workspace));
    }

    public function test_application_logs_are_redacted_by_default(): void
    {
        $path = storage_path('logs/laravel.log');
        File::delete($path);

        Log::channel('single')->info('token=super-secret-value', [
            'OPENAI_API_KEY' => 'sk-proj-abcdefghijklmnopqrstuvwxyz123456',
            'session_id' => 'session-visible-for-cli-continuity',
        ]);

        $log = File::get($path);

        $this->assertStringContainsString('[redacted]', $log);
        $this->assertStringContainsString('session-visible-for-cli-continuity', $log);
        $this->assertStringNotContainsString('super-secret-value', $log);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz123456', $log);
    }
}

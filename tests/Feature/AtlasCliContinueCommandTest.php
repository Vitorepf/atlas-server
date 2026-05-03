<?php

namespace Tests\Feature;

use App\Services\Ai\Cli\AtlasCliSessionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliContinueCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-continue-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        (new Process(['git', 'init'], $this->workspace))->run();

        config(['atlas.cli.php_binary' => '/opt/homebrew/bin/php']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_continue_returns_success_with_guidance_when_nothing_to_resume(): void
    {
        $exit = Artisan::call('atlas:cli:continue', [
            '--workspace' => $this->workspace,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('nada a retomar', $output);
        $this->assertStringContainsString('atlas dev', $output);
    }

    public function test_continue_emits_json_payload_when_nothing_to_resume(): void
    {
        $exit = Artisan::call('atlas:cli:continue', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['resumed']);
        $this->assertSame($this->workspace, $payload['workspace']);
    }

    public function test_continue_dry_run_uses_configured_php_binary_for_resume_command(): void
    {
        $this->mock(AtlasCliSessionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findResumablePlan')
                ->once()
                ->with($this->workspace, null)
                ->andReturn([
                    'plan_id' => 'plan_123',
                    'task' => 'continuar memoria',
                    'workspace' => $this->workspace,
                    'thread_id' => 'thread_123',
                    'trace_id' => 'trace_123',
                    'reason' => 'fase execute',
                    'operator_options' => [
                        'provider' => 'codex_cli',
                        'open_brain' => ['mode' => 'required'],
                    ],
                ]);
        });

        $exit = Artisan::call('atlas:cli:continue', [
            '--workspace' => $this->workspace,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['resumed']);
        $this->assertStringStartsWith('/opt/homebrew/bin/php ', $payload['command']);
        $this->assertStringContainsString('atlas:cli:dev', $payload['command']);
        $this->assertStringContainsString('--require-open-brain', $payload['command']);
    }
}

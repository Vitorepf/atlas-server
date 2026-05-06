<?php

namespace Tests\Feature;

use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Programming\AtlasProgrammingSurfaceCommandBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\TestCase;

class AtlasCliContinueCommandTest extends TestCase
{
    use CreatesAiJobChoiceTables;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiJobChoiceTables();
        $this->workspace = sys_get_temp_dir().'/atlas-cli-continue-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        (new Process(['git', 'init'], $this->workspace))->run();

        config(['atlas.cli.php_binary' => '/opt/homebrew/bin/php']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        $this->dropAiJobChoiceTables();

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
                    'programming_profile' => 'forge',
                    'model' => 'gpt-5.5',
                    'operator_options' => [
                        'provider' => 'codex_cli',
                        'programming_intent' => 'repair',
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
        $this->assertStringContainsString('--forge', $payload['command']);
        $this->assertStringContainsString('--repair', $payload['command']);
        $this->assertStringContainsString('--model=gpt-5.5', $payload['command']);
        $this->assertSame('atlas.cli_continue.resume_contract.v1', data_get($payload, 'resume_contract.schema_version'));
        $this->assertSame('atlas_cli_continue', data_get($payload, 'resume_contract.surface'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'resume_contract.canonical_surface'));
        $this->assertSame('atlas:cli:dev', data_get($payload, 'resume_contract.target_command'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'resume_contract.target_surface'));
        $this->assertSame(AtlasProgrammingSurfaceCommandBuilder::class, data_get($payload, 'resume_contract.builder'));
        $this->assertSame('plan_123', data_get($payload, 'resume_contract.plan_id'));
        $this->assertSame('forge', data_get($payload, 'resume_contract.programming_profile'));
        $this->assertSame('repair', data_get($payload, 'resume_contract.programming_intent'));
        $this->assertSame('codex_cli', data_get($payload, 'resume_contract.provider'));
        $this->assertSame('gpt-5.5', data_get($payload, 'resume_contract.model'));
        $this->assertTrue(data_get($payload, 'resume_contract.dev_flags.resume'));
        $this->assertTrue(data_get($payload, 'resume_contract.dev_flags.forge'));
        $this->assertTrue(data_get($payload, 'resume_contract.dev_flags.repair'));
        $this->assertSame('required', data_get($payload, 'resume_contract.open_brain.mode'));
    }

    public function test_continue_resumes_programming_session_plan_without_legacy_dev_plan(): void
    {
        $thread = AiThread::query()->create([
            'id' => (string) Str::orderedUuid(),
            'surface' => 'atlas_cli',
            'workspace' => $this->workspace,
            'status' => 'active',
            'last_message_at' => now(),
        ]);

        AiTrace::query()->create([
            'id' => (string) Str::orderedUuid(),
            'trace_key' => 'trace_continue_programming_session',
            'thread_id' => $thread->id,
            'status' => 'failed',
            'operator_input' => 'continuar fluxo novo',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'metadata' => [
                'programming_profile' => 'forge',
                'programming_session_plan' => [
                    'plan_id' => 'programming-plan-456',
                    'workspace' => $this->workspace,
                    'programming_profile' => 'forge',
                    'operator_options' => [
                        'provider' => 'codex_cli',
                        'programming_intent' => 'repair',
                        'allow_write' => true,
                    ],
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:cli:continue', [
            '--workspace' => $this->workspace,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['resumed']);
        $this->assertSame('programming-plan-456', $payload['plan_id']);
        $this->assertSame('forge', $payload['programming_profile']);
        $this->assertStringContainsString('--forge', $payload['command']);
        $this->assertStringContainsString('--repair', $payload['command']);
        $this->assertStringContainsString('--model=gpt-5.5', $payload['command']);
        $this->assertSame('atlas.cli_continue.resume_contract.v1', data_get($payload, 'resume_contract.schema_version'));
        $this->assertSame('atlas_cli_continue', data_get($payload, 'resume_contract.surface'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'resume_contract.canonical_surface'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'resume_contract.target_surface'));
        $this->assertSame('programming-plan-456', data_get($payload, 'resume_contract.plan_id'));
        $this->assertSame('forge', data_get($payload, 'resume_contract.programming_profile'));
        $this->assertSame('repair', data_get($payload, 'resume_contract.programming_intent'));
        $this->assertTrue(data_get($payload, 'resume_contract.dev_flags.resume'));
        $this->assertTrue(data_get($payload, 'resume_contract.dev_flags.allow_write'));
    }
}

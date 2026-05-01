<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliQualityService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliQualityServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-quality-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => 'php -r "exit(0);"',
            ],
        ], JSON_PRETTY_PRINT));
        File::put($this->workspace.'/note.txt', 'changed');
        (new Process(['git', 'init'], $this->workspace))->run();

        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_quality_gate_builds_completion_packet_for_dirty_workspace(): void
    {
        $payload = app(AtlasCliQualityService::class)->evaluate($this->workspace);

        $this->assertSame('needs_review', $payload['status']);
        $this->assertContains('note.txt', $payload['changed_files']);
        $this->assertSame('needs_review', $payload['completion_packet']['status']);
        $this->assertNotEmpty($payload['completion_packet']['risks']);
    }

    public function test_quality_gate_can_build_compact_packet(): void
    {
        $service = app(AtlasCliQualityService::class);
        $compact = $service->compact($service->evaluate($this->workspace), fileLimit: 1);

        $this->assertSame('needs_review', $compact['status']);
        $this->assertArrayHasKey('changed_files_preview', $compact);
        $this->assertArrayNotHasKey('diff_excerpt', $compact);
        $this->assertLessThanOrEqual(1, count($compact['changed_files_preview']));
    }

    public function test_compact_packet_truncates_large_test_failures(): void
    {
        $compact = app(AtlasCliQualityService::class)->compact([
            'workspace' => $this->workspace,
            'generated_at' => now()->toJSON(),
            'status' => 'failed',
            'changed_files' => ['note.txt'],
            'dirty_count' => 1,
            'test_commands_detected' => ['php artisan test'],
            'test_result' => [
                'ok' => false,
                'exit_code' => 1,
                'duration_ms' => 10,
                'error_message' => str_repeat('failure ', 5000),
                'metadata' => [
                    'command_display' => escapeshellarg(PHP_BINARY).' artisan test',
                ],
            ],
            'quality_gates' => [],
            'completion_packet' => [
                'status' => 'failed',
                'summary' => 'Tests failed.',
                'risks' => ['Teste falhou.'],
                'tests' => [],
            ],
        ]);

        $this->assertLessThanOrEqual(12500, strlen((string) $compact['test_result']['error']));
        $this->assertStringContainsString('[middle output truncated by Atlas; showing final lines below]', (string) $compact['test_result']['error']);
        $this->assertSame(escapeshellarg(PHP_BINARY).' artisan test', $compact['test_result']['command']);
    }

    public function test_compact_packet_preserves_tail_for_long_test_failures(): void
    {
        $compact = app(AtlasCliQualityService::class)->compact([
            'workspace' => $this->workspace,
            'generated_at' => now()->toJSON(),
            'status' => 'failed',
            'changed_files' => ['note.txt'],
            'dirty_count' => 1,
            'test_commands_detected' => ['php artisan test'],
            'test_result' => [
                'ok' => false,
                'exit_code' => 1,
                'duration_ms' => 10,
                'error_message' => str_repeat("PASS ExampleTest\n", 1200)."\nFAILED Tests\\Feature\\CriticalFailureTest > regression broke\nTests: 1 failed, 193 passed\n",
            ],
            'quality_gates' => [],
            'completion_packet' => [
                'status' => 'failed',
                'summary' => 'Tests failed.',
                'risks' => ['Teste falhou.'],
                'tests' => [],
            ],
        ]);

        $error = (string) $compact['test_result']['error'];

        $this->assertStringContainsString('FAILED Tests\\Feature\\CriticalFailureTest', $error);
        $this->assertStringContainsString('Tests: 1 failed, 193 passed', $error);
    }


    public function test_quality_gate_passes_dirty_workspace_when_tests_pass_for_complete_mode(): void
    {
        $payload = app(AtlasCliQualityService::class)->evaluate(
            workspace: $this->workspace,
            runTests: true,
            approved: true,
        );

        $this->assertSame('passed', $payload['status']);
        $this->assertSame('passed', $payload['completion_packet']['status']);
        $this->assertSame('passed', collect($payload['quality_gates'])->firstWhere('name', 'workspace_changes')['status']);
        $this->assertSame('passed', collect($payload['quality_gates'])->firstWhere('name', 'tests')['status']);
    }
}

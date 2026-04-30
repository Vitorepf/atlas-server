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

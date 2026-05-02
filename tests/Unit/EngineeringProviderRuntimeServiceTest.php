<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringProviderRuntimeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EngineeringProviderRuntimeServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-provider-runtime-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_explicit_docker_runtime_is_unavailable_without_compose_file(): void
    {
        $plan = app(EngineeringProviderRuntimeService::class)->plan([
            'execution_workspace' => $this->workspace,
        ], [
            'provider_runtime' => 'docker',
            'provider_docker_compose_file' => $this->workspace.'/missing-compose.yml',
            'provider_docker_service' => 'backend',
        ]);

        $this->assertSame('docker', $plan['requested_runtime']);
        $this->assertSame('docker', $plan['runtime']);
        $this->assertSame('unavailable', $plan['status']);
        $this->assertTrue((bool) $plan['required']);
        $this->assertSame('provider_docker_compose_file_missing', $plan['fallback_reason']);
    }

    public function test_docker_provider_command_mounts_workspace_and_rewrites_workspace_argument(): void
    {
        $service = app(EngineeringProviderRuntimeService::class);
        $hostCommand = [
            PHP_BINARY,
            base_path('artisan'),
            'atlas:cli:dev',
            '--task-id=task-123',
            '--workspace='.$this->workspace,
            '--json',
            '--no-progress',
        ];

        $command = $service->command($hostCommand, [
            'execution_workspace' => $this->workspace,
        ], [
            'runtime' => 'docker',
            'status' => 'ready',
            'compose_file' => base_path('docker-compose.yml'),
            'service' => 'backend',
            'app_dir' => '/app',
            'workspace_dir' => '/workspace',
        ]);

        $this->assertSame('docker', $command['runtime']);
        $this->assertSame('docker', $command['command'][0]);
        $this->assertSame('compose', $command['command'][1]);
        $this->assertContains('-v', $command['command']);
        $this->assertContains($this->workspace.':/workspace', $command['command']);
        $this->assertContains('backend', $command['command']);
        $this->assertContains('atlas:cli:dev', $command['command']);
        $this->assertContains('--workspace=/workspace', $command['command']);
        $this->assertNotContains('--workspace='.$this->workspace, $command['command']);
    }
}

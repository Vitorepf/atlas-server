<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringDockerHarnessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EngineeringDockerHarnessServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-docker-harness-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::deleteDirectory(storage_path('app/engineering-cache/docker'));

        parent::tearDown();
    }

    public function test_augment_profile_detects_dependency_caches_and_artifact_policy(): void
    {
        File::put($this->workspace.'/package.json', '{"scripts":{"test":"echo ok"}}');
        File::put($this->workspace.'/composer.json', '{"require":{}}');

        $profile = app(EngineeringDockerHarnessService::class)->augmentProfile($this->workspace, [
            'runtime' => 'compose',
            'selected_compose_file' => 'docker-compose.yml',
            'service' => 'app',
        ], [
            'docker_artifact_paths' => ['coverage', 'test-results'],
            'docker_healthcheck_services' => ['db'],
        ]);

        $this->assertTrue((bool) data_get($profile, 'cache.enabled'));
        $this->assertSame(['npm', 'composer'], collect(data_get($profile, 'cache.mounts', []))->pluck('name')->all());
        $this->assertSame(['db'], data_get($profile, 'healthchecks.services'));
        $this->assertSame(['coverage', 'test-results'], data_get($profile, 'artifacts.paths'));
    }

    public function test_containerized_test_command_includes_cache_mounts_and_env(): void
    {
        File::put($this->workspace.'/package.json', '{"scripts":{"test":"echo ok"}}');
        $service = app(EngineeringDockerHarnessService::class);
        $profile = $service->augmentProfile($this->workspace, [
            'runtime' => 'compose',
            'selected_compose_file' => 'docker-compose.yml',
            'service' => 'app',
            'container_workdir' => '/workspace',
        ]);

        $runtime = $service->testRuntimeCommand('npm test', $this->workspace, [
            'mode' => 'docker',
            'containerized_execution' => true,
            'execution_workspace' => $this->workspace,
            'docker' => $profile,
        ]);

        $this->assertSame('docker_compose', $runtime['runtime']);
        $this->assertStringContainsString('NPM_CONFIG_CACHE=/cache/npm', $runtime['command']);
        $this->assertStringContainsString('/cache/npm', $runtime['command']);
        $this->assertStringContainsString($this->workspace.':/workspace', $runtime['command']);
    }

    public function test_dockerfile_network_policy_is_enforced_in_runtime_command(): void
    {
        File::put($this->workspace.'/Dockerfile', "FROM alpine:3.20\n");

        $service = app(EngineeringDockerHarnessService::class);
        $profile = $service->augmentProfile($this->workspace, [
            'runtime' => 'dockerfile',
            'dockerfile' => 'Dockerfile',
            'container_workdir' => '/workspace',
        ], [
            'docker_network' => 'none',
        ]);
        $workspacePlan = [
            'mode' => 'docker',
            'containerized_execution' => true,
            'execution_workspace' => $this->workspace,
            'docker' => $profile,
        ];

        $runtime = $service->testRuntimeCommand('npm test', $this->workspace, $workspacePlan);
        $status = $service->networkPolicyStatus($workspacePlan);

        $this->assertSame('dockerfile', $runtime['runtime']);
        $this->assertStringContainsString('--network', $runtime['command']);
        $this->assertStringContainsString('none', $runtime['command']);
        $this->assertSame('passed', $status['status']);
        $this->assertTrue((bool) $status['enforced']);
    }

    public function test_compose_network_policy_reports_unenforceable_when_not_profile_based(): void
    {
        $service = app(EngineeringDockerHarnessService::class);
        $profile = $service->augmentProfile($this->workspace, [
            'runtime' => 'compose',
            'selected_compose_file' => 'docker-compose.yml',
            'service' => 'app',
            'container_workdir' => '/workspace',
        ], [
            'docker_network' => 'none',
        ]);
        $workspacePlan = [
            'mode' => 'docker',
            'containerized_execution' => true,
            'execution_workspace' => $this->workspace,
            'docker' => $profile,
        ];

        $runtime = $service->testRuntimeCommand('npm test', $this->workspace, $workspacePlan);
        $status = $service->networkPolicyStatus($workspacePlan);

        $this->assertSame('docker_compose', $runtime['runtime']);
        $this->assertStringNotContainsString('--network', $runtime['command']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('docker_compose_network_policy_requires_compose_profile', $status['reason']);
    }

    public function test_cleanup_dry_run_and_apply_cover_old_caches_and_artifacts(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $cacheRoot = storage_path('app/engineering-cache/docker/cleanup-'.$suffix);
        $runRoot = storage_path('app/engineering-runs/cleanup-'.$suffix);
        $artifactRoot = $runRoot.'/test-artifacts';
        File::ensureDirectoryExists($cacheRoot);
        File::ensureDirectoryExists($artifactRoot);
        File::put($cacheRoot.'/cache.txt', 'cache');
        File::put($artifactRoot.'/report.txt', 'artifact');

        $oldTimestamp = time() - (3 * 24 * 60 * 60);
        touch($cacheRoot.'/cache.txt', $oldTimestamp);
        touch($artifactRoot.'/report.txt', $oldTimestamp);
        touch($cacheRoot, $oldTimestamp);
        touch($artifactRoot, $oldTimestamp);

        $service = app(EngineeringDockerHarnessService::class);
        $dryRun = $service->cleanup(true, [
            'cache_retention_days' => 1,
            'artifact_retention_days' => 1,
        ]);
        $candidateHashes = collect($dryRun['candidates'])->pluck('path_hash')->all();

        $this->assertTrue((bool) $dryRun['dry_run']);
        $this->assertContains(hash('sha256', $cacheRoot), $candidateHashes);
        $this->assertContains(hash('sha256', $artifactRoot), $candidateHashes);
        $this->assertDirectoryExists($cacheRoot);
        $this->assertDirectoryExists($artifactRoot);

        $applied = $service->cleanup(false, [
            'cache_retention_days' => 1,
            'artifact_retention_days' => 1,
        ]);
        $deletedHashes = collect($applied['deleted'])->pluck('path_hash')->all();

        $this->assertFalse((bool) $applied['dry_run']);
        $this->assertContains(hash('sha256', $cacheRoot), $deletedHashes);
        $this->assertContains(hash('sha256', $artifactRoot), $deletedHashes);
        $this->assertDirectoryDoesNotExist($cacheRoot);
        $this->assertDirectoryDoesNotExist($artifactRoot);

        File::deleteDirectory($runRoot);
    }
}

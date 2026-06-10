<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProviderRuntimeEnvironment;
use Tests\TestCase;

final class ProviderRuntimeEnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ATLAS_PROVIDER_RUNTIME_ENV_TEST');

        parent::tearDown();
    }

    public function test_resolve_executable_handles_absolute_and_path_binaries(): void
    {
        $this->assertSame(PHP_BINARY, ProviderRuntimeEnvironment::resolveExecutable(PHP_BINARY));
        $this->assertNull(ProviderRuntimeEnvironment::resolveExecutable(''));
    }

    public function test_resolve_nested_executable_uses_fallback_when_runtime_binary_is_missing(): void
    {
        $root = sys_get_temp_dir().'/atlas_provider_runtime_env_'.uniqid('', true);
        @mkdir($root.'/bin', 0775, true);

        try {
            $binary = $root.'/bin/tool';
            file_put_contents($binary, "#!/bin/sh\nexit 0\n");
            chmod($binary, 0755);

            $this->assertSame($binary, ProviderRuntimeEnvironment::resolveNestedExecutable($root, 'bin/tool', 'fallback-tool'));
            $this->assertSame('fallback-tool', ProviderRuntimeEnvironment::resolveNestedExecutable($root, 'missing/tool', 'fallback-tool'));
        } finally {
            @unlink($root.'/bin/tool');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }

    public function test_auth_state_reads_configured_environment_variable(): void
    {
        putenv('ATLAS_PROVIDER_RUNTIME_ENV_TEST=secret');

        $this->assertSame('configured', ProviderRuntimeEnvironment::authState(['ATLAS_PROVIDER_RUNTIME_ENV_TEST']));

        putenv('ATLAS_PROVIDER_RUNTIME_ENV_TEST');

        $this->assertSame('missing', ProviderRuntimeEnvironment::authState(['ATLAS_PROVIDER_RUNTIME_ENV_TEST']));
    }

    public function test_workspace_path_accepts_manifest_aliases(): void
    {
        $this->assertSame('/tmp/a', ProviderRuntimeEnvironment::workspacePath(['workspace' => ['path' => ' /tmp/a ']]));
        $this->assertSame('/tmp/b', ProviderRuntimeEnvironment::workspacePath(['workspace_path' => '/tmp/b']));
        $this->assertSame('/tmp/c', ProviderRuntimeEnvironment::workspacePath(['cwd' => '/tmp/c']));
        $this->assertNull(ProviderRuntimeEnvironment::workspacePath([]));
    }
}

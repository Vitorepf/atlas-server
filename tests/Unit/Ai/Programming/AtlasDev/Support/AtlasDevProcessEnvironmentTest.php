<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevProcessEnvironment;
use PHPUnit\Framework\TestCase;

final class AtlasDevProcessEnvironmentTest extends TestCase
{
    public function test_verification_command_env_exposes_only_basic_process_keys(): void
    {
        $this->withEnv([
            'HOME' => '/tmp/atlas-dev-home/',
            'PATH' => '/tmp/atlas-dev-bin',
            'USER' => 'atlas-user',
            'LOGNAME' => 'atlas-log',
            'CLAUDE_CONFIG_DIR' => '/tmp/atlas-claude',
        ], function (): void {
            $env = AtlasDevProcessEnvironment::verificationCommandEnv();

            $this->assertSame('/tmp/atlas-dev-home/', $env['HOME'] ?? null);
            $this->assertSame('/tmp/atlas-dev-bin', $env['PATH'] ?? null);
            $this->assertSame('atlas-user', $env['USER'] ?? null);
            $this->assertSame('atlas-log', $env['LOGNAME'] ?? null);
            $this->assertArrayNotHasKey('CLAUDE_CONFIG_DIR', $env);
        });
    }

    public function test_claude_provider_env_trims_home_and_carries_claude_config_dir(): void
    {
        $this->withEnv([
            'HOME' => '/tmp/atlas-dev-home/',
            'PATH' => '/tmp/atlas-dev-bin',
            'USER' => 'atlas-user',
            'LOGNAME' => 'atlas-log',
            'CLAUDE_CONFIG_DIR' => '/tmp/atlas-claude',
        ], function (): void {
            $env = AtlasDevProcessEnvironment::claudeProviderEnv();

            $this->assertSame('/tmp/atlas-dev-home', $env['HOME'] ?? null);
            $this->assertSame('/tmp/atlas-claude', $env['CLAUDE_CONFIG_DIR'] ?? null);
            $this->assertSame('/tmp/atlas-dev-bin', $env['PATH'] ?? null);
            $this->assertSame('atlas-user', $env['USER'] ?? null);
            $this->assertSame('atlas-log', $env['LOGNAME'] ?? null);
        });
    }

    /**
     * @param  array<string, string>  $values
     */
    private function withEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$value);
                }
            }
        }
    }
}

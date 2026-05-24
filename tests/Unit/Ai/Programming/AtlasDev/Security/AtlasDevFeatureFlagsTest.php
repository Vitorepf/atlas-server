<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Security;

use PHPUnit\Framework\TestCase;

final class AtlasDevFeatureFlagsTest extends TestCase
{
    private array $atlasDevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forceEnv('ATLAS_DEV_RECEIPTS_PATH', sys_get_temp_dir().'/atlas-dev/receipts');
        $this->atlasDevConfig = require $this->repoPath('config/atlas_dev.php');
    }

    public function test_feature_flag_keys_exist(): void
    {
        $flags = $this->atlasDevConfig['efficient'];

        $this->assertIsArray($flags);
        $this->assertArrayHasKey('enabled', $flags);
        $this->assertArrayHasKey('plan_enabled', $flags);
        $this->assertArrayHasKey('run_enabled', $flags);
        $this->assertArrayHasKey('desktop_enabled', $flags);
    }

    public function test_run_and_desktop_are_off_by_default(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));

        $this->assertStringContainsString("'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', false)", $configSource);
        $this->assertStringContainsString("'desktop_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED', false)", $configSource);
    }

    public function test_plan_enabled_is_on_under_testing_environment(): void
    {
        $this->assertTrue((bool) $this->atlasDevConfig['efficient']['plan_enabled']);
        $this->assertTrue((bool) $this->atlasDevConfig['efficient']['enabled']);
    }

    public function test_confirmation_token_defaults_are_safe(): void
    {
        $this->assertSame(300, (int) $this->atlasDevConfig['confirmation_token']['ttl_seconds']);
        $this->assertGreaterThanOrEqual(16, (int) $this->atlasDevConfig['confirmation_token']['plaintext_bytes']);
    }

    public function test_run_index_limits_are_sane(): void
    {
        $default = (int) $this->atlasDevConfig['run_index']['list_default_limit'];
        $max = (int) $this->atlasDevConfig['run_index']['list_max_limit'];

        $this->assertGreaterThan(0, $default);
        $this->assertGreaterThanOrEqual($default, $max);
    }

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 6).'/'.$relative;
    }

    private function forceEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

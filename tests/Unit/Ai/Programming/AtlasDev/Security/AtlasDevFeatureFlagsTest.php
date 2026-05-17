<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Security;

use Tests\TestCase;

final class AtlasDevFeatureFlagsTest extends TestCase
{
    public function test_feature_flag_keys_exist(): void
    {
        $flags = config('atlas_dev.efficient');

        $this->assertIsArray($flags);
        $this->assertArrayHasKey('enabled', $flags);
        $this->assertArrayHasKey('plan_enabled', $flags);
        $this->assertArrayHasKey('run_enabled', $flags);
        $this->assertArrayHasKey('desktop_enabled', $flags);
    }

    public function test_run_and_desktop_are_off_by_default(): void
    {
        $configSource = (string) file_get_contents(config_path('atlas_dev.php'));

        $this->assertStringContainsString("'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', false)", $configSource);
        $this->assertStringContainsString("'desktop_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED', false)", $configSource);
    }

    public function test_plan_enabled_is_on_under_testing_environment(): void
    {
        $this->assertTrue((bool) config('atlas_dev.efficient.plan_enabled'));
        $this->assertTrue((bool) config('atlas_dev.efficient.enabled'));
    }

    public function test_confirmation_token_defaults_are_safe(): void
    {
        $this->assertSame(300, (int) config('atlas_dev.confirmation_token.ttl_seconds'));
        $this->assertGreaterThanOrEqual(16, (int) config('atlas_dev.confirmation_token.plaintext_bytes'));
    }

    public function test_run_index_limits_are_sane(): void
    {
        $default = (int) config('atlas_dev.run_index.list_default_limit');
        $max = (int) config('atlas_dev.run_index.list_max_limit');

        $this->assertGreaterThan(0, $default);
        $this->assertGreaterThanOrEqual($default, $max);
    }
}

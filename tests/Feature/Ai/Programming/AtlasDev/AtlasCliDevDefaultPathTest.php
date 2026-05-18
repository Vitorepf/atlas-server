<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Console\Commands\AtlasCliDevCommand;
use Tests\TestCase;

final class AtlasCliDevDefaultPathTest extends TestCase
{
    public function test_efficient_is_the_canonical_default_path(): void
    {
        $this->assertSame(
            'efficient',
            config('atlas_dev.efficient.default_path'),
            'Atlas Dev efficient pipeline must be the canonical default path.',
        );
    }

    public function test_plan_enabled_default_is_true_in_all_envs(): void
    {
        $this->assertTrue(
            (bool) config('atlas_dev.efficient.plan_enabled'),
            'plan_enabled must default to true so efficient is available everywhere.',
        );
    }

    public function test_enabled_master_flag_default_is_true(): void
    {
        $this->assertTrue((bool) config('atlas_dev.efficient.enabled'));
    }

    public function test_legacy_fallback_is_explicit(): void
    {
        // The CLI exposes both `--efficient` and `--legacy` flags so the
        // operator can override the config default in either direction.
        $signature = (string) (new AtlasCliDevCommand)->getDefinition()->getSynopsis();
        $this->assertStringContainsString('--efficient', $signature);
        $this->assertStringContainsString('--legacy', $signature);
    }
}

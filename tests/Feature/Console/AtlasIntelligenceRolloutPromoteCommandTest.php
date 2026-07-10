<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class AtlasIntelligenceRolloutPromoteCommandTest extends TestCase
{
    public function test_dry_run_with_skip_gates_emits_receipt_without_writing_env(): void
    {
        $envPath = base_path('.env');
        $before = is_file($envPath) ? (string) file_get_contents($envPath) : null;

        $this->artisan('atlas:intelligence:rollout-promote', [
            'feature' => 'unified_retrieval',
            '--to' => 'shadow',
            '--skip-gates' => true,
            '--json' => true,
        ])->assertSuccessful();

        $after = is_file($envPath) ? (string) file_get_contents($envPath) : null;
        $this->assertSame($before, $after);

        $jsonl = storage_path('atlas/intelligence/rollout_promotions.jsonl');
        $this->assertFileExists($jsonl);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($jsonl))));
        $last = json_decode((string) end($lines), true);
        $this->assertSame('atlas.intelligence.rollout_promotion.v1', $last['schema_version'] ?? null);
        $this->assertSame('dry_run', $last['mode'] ?? null);
        $this->assertSame('shadow', $last['target_mode'] ?? null);
        $this->assertFalse((bool) ($last['written'] ?? true));
    }

    public function test_off_kill_switch_targets_offline(): void
    {
        $this->artisan('atlas:intelligence:rollout-promote', [
            'feature' => 'fusion',
            '--off' => true,
            '--skip-gates' => true,
            '--json' => true,
        ])->assertSuccessful();

        $jsonl = storage_path('atlas/intelligence/rollout_promotions.jsonl');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($jsonl))));
        $last = json_decode((string) end($lines), true);
        $this->assertSame('offline', $last['target_mode'] ?? null);
        $this->assertTrue((bool) ($last['kill_switch'] ?? false));
    }

    protected function tearDown(): void
    {
        $jsonl = storage_path('atlas/intelligence/rollout_promotions.jsonl');
        if (is_file($jsonl)) {
            @unlink($jsonl);
        }
        parent::tearDown();
    }
}

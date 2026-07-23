<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConfigSurfaceReducer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConfigSurfaceReducerTest extends TestCase
{
    private function svc(): AtlasExternalBrainConfigSurfaceReducer
    {
        return new AtlasExternalBrainConfigSurfaceReducer;
    }

    private function activeKey(array $overrides = []): array
    {
        return array_merge([
            'key' => 'app.feature_x',
            'usage_known' => true,
            'usage_count' => 5,
            'last_used_days_ago' => 1,
            'has_default' => true,
            'overridden' => false,
            'runtime_critical' => false,
            'duplicate_of' => '',
        ], $overrides);
    }

    // ── keep ────────────────────────────────────────────────────────────────────

    public function test_actively_used_key_is_kept(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey()]]);

        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_KEEP, $r['decisions'][0]['action']);
        $this->assertSame('actively_used', $r['decisions'][0]['reason']);
    }

    // ── AC: stale_config_delete_case ───────────────────────────────────────────

    public function test_stale_config_delete_case(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'usage_count' => 0,
            'last_used_days_ago' => 120,
            'overridden' => false,
        ])]]);

        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_DELETE, $r['decisions'][0]['action']);
        $this->assertSame('stale_unused', $r['decisions'][0]['reason']);
        $this->assertSame(1, $r['summary']['delete_count']);
    }

    public function test_stale_but_overridden_key_is_not_deleted(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'usage_count' => 0,
            'last_used_days_ago' => 120,
            'overridden' => true,
        ])]]);

        $this->assertNotSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_DELETE, $r['decisions'][0]['action']);
    }

    public function test_recently_unused_key_below_threshold_is_not_deleted(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'usage_count' => 0,
            'last_used_days_ago' => 10,
        ])]]);

        $this->assertNotSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_DELETE, $r['decisions'][0]['action']);
    }

    // ── merge ──────────────────────────────────────────────────────────────────

    public function test_duplicate_key_still_in_use_is_merged(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'duplicate_of' => 'app.canonical_feature',
        ])]]);

        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_MERGE, $r['decisions'][0]['action']);
        $this->assertSame('duplicate_of:app.canonical_feature', $r['decisions'][0]['reason']);
        $this->assertSame('app.canonical_feature', $r['decisions'][0]['duplicate_of']);
    }

    public function test_duplicate_key_with_zero_usage_is_not_merged(): void
    {
        // Zero usage + duplicate -- stale takes priority only if also past threshold; otherwise keep.
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'duplicate_of' => 'app.canonical_feature',
            'usage_count' => 0,
            'last_used_days_ago' => 1,
        ])]]);

        $this->assertNotSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_MERGE, $r['decisions'][0]['action']);
    }

    // ── AC: runtime_critical_hold_case ─────────────────────────────────────────

    public function test_runtime_critical_hold_case(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'runtime_critical' => true,
            'usage_count' => 0,
            'last_used_days_ago' => 200, // would otherwise qualify for delete
        ])]]);

        $entry = $r['decisions'][0];
        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_PROOF_FIRST, $entry['action']);
        $this->assertSame('runtime_critical', $entry['reason']);
        $this->assertNotEmpty($entry['required_proof']);
        $this->assertSame(1, $r['summary']['proof_first_count']);
    }

    public function test_unknown_usage_holds_with_proof_first(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'usage_known' => false,
        ])]]);

        $entry = $r['decisions'][0];
        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_PROOF_FIRST, $entry['action']);
        $this->assertSame('usage_unknown', $entry['reason']);
        $this->assertNotEmpty($entry['required_proof']);
    }

    public function test_unknown_usage_takes_priority_over_stale_delete(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'usage_known' => false,
            'usage_count' => 0,
            'last_used_days_ago' => 200,
        ])]]);

        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_PROOF_FIRST, $r['decisions'][0]['action']);
    }

    public function test_runtime_critical_takes_priority_over_merge(): void
    {
        $r = $this->svc()->reduce(['config_keys' => [$this->activeKey([
            'runtime_critical' => true,
            'duplicate_of' => 'app.canonical_feature',
        ])]]);

        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::ACTION_PROOF_FIRST, $r['decisions'][0]['action']);
    }

    // ── malformed / empty ──────────────────────────────────────────────────────

    public function test_malformed_entry_is_skipped(): void
    {
        $r = $this->svc()->reduce(['config_keys' => ['not-an-array', $this->activeKey()]]);

        $this->assertCount(1, $r['decisions']);
    }

    public function test_empty_config_keys_yields_empty_result(): void
    {
        $r = $this->svc()->reduce(['config_keys' => []]);

        $this->assertSame([], $r['decisions']);
        $this->assertSame(0, $r['summary']['total']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->reduce(['config_keys' => []]);

        $this->assertSame(AtlasExternalBrainConfigSurfaceReducer::SCHEMA, $r['schema']);
    }

    public function test_reduce_is_deterministic(): void
    {
        $input = ['config_keys' => [$this->activeKey(), $this->activeKey(['key' => 'app.other', 'runtime_critical' => true])]];

        $this->assertSame(
            $this->svc()->reduce($input),
            $this->svc()->reduce($input),
        );
    }
}

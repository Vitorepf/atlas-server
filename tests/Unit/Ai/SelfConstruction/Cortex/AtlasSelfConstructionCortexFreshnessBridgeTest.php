<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexFreshnessBridge;
use Tests\TestCase;

final class AtlasSelfConstructionCortexFreshnessBridgeTest extends TestCase
{
    private function allFreshFacts(): array
    {
        $now = 1700000000;
        $sources = [];
        foreach (AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES as $src) {
            $sources[$src] = ['last_unix' => $now - 100, 'hash' => 'hash_'.$src];
        }

        return ['now_unix' => $now, 'sources' => $sources];
    }

    // ── AC3: all fresh sources → safe_to_origin_tasks=true ───────────────────

    public function test_all_fresh_sources_produce_safe_to_origin_tasks_true(): void
    {
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt($this->allFreshFacts());

        $this->assertTrue($r['all_fresh']);
        $this->assertTrue($r['safe_to_origin_tasks']);
        $this->assertSame([], $r['blocking_refresh_plan']);
    }

    // ── AC1: missing/blocked/future/invalid block safe_to_origin_tasks ───────

    public function test_missing_source_blocks_with_exact_refresh_action(): void
    {
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => 1700000000,
            'sources' => [], // all missing
        ]);

        $this->assertFalse($r['safe_to_origin_tasks']);
        $this->assertNotEmpty($r['blocking_refresh_plan']);
        $action = array_column($r['blocking_refresh_plan'], 'refresh_action');
        $this->assertContains('supply_source', $action);
    }

    public function test_future_timestamp_blocks(): void
    {
        $now = 1700000000;
        $sources = [];
        foreach (AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES as $src) {
            $sources[$src] = ['last_unix' => $now + 9999, 'hash' => 'h'];
        }

        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);

        $this->assertFalse($r['safe_to_origin_tasks']);
        $this->assertNotEmpty($r['blocking_refresh_plan']);
    }

    public function test_invalid_window_blocks_all_sources(): void
    {
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => 1700000000,
            'freshness_window_seconds' => 0,
            'sources' => [],
        ]);

        $this->assertFalse($r['safe_to_origin_tasks']);
        foreach ($r['rows'] as $row) {
            $this->assertSame('blocked', $row['readiness']);
        }
    }

    // ── AC2: stale but hash-present → advisory, stale_but_usable ─────────────

    public function test_stale_source_with_hash_is_advisory_and_usable(): void
    {
        $now = 1700000000;
        $sources = [];
        foreach (AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES as $src) {
            $sources[$src] = ['last_unix' => $now - 999999, 'hash' => 'old_hash']; // stale
        }

        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);

        $this->assertFalse($r['safe_to_origin_tasks']);
        $this->assertTrue($r['stale_but_usable']);
        $this->assertNotEmpty($r['advisory_refresh_plan']);
        $this->assertSame([], $r['blocking_refresh_plan']);
    }

    // ── output shape ─────────────────────────────────────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => 0]);

        foreach (['schema', 'all_fresh', 'safe_to_origin_tasks', 'stale_but_usable', 'rows', 'blocking_refresh_plan', 'advisory_refresh_plan'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
    }

    public function test_required_sources_includes_worker_outcome_and_project_lane(): void
    {
        $this->assertContains('worker_outcome', AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES);
        $this->assertContains('project_lane', AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES);
    }
}

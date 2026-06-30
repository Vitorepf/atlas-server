<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexFreshnessBridge;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionCortexFreshnessBridge: complete fresh inputs ⇒ every row fresh and
 * all_fresh=true; stale last_unix ⇒ readiness=stale; missing source row ⇒ readiness=unknown; row with
 * empty hash ⇒ readiness=blocked; rows sorted byte-stably by source_id.
 */
final class AtlasSelfConstructionCortexFreshnessBridgeTest extends TestCase
{
    private function freshSources(int $now): array
    {
        $out = [];
        foreach (AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES as $s) {
            $out[$s] = ['last_unix' => $now - 60, 'hash' => 'h-'.$s];
        }

        return $out;
    }

    public function test_all_fresh_inputs_yield_all_fresh_true(): void
    {
        $now = time();
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => $now,
            'sources' => $this->freshSources($now),
        ]);
        $this->assertTrue($r['all_fresh']);
        foreach ($r['rows'] as $row) {
            $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::FRESH, $row['readiness']);
        }
    }

    public function test_stale_last_unix_yields_stale(): void
    {
        $now = time();
        $sources = $this->freshSources($now);
        $sources['docs']['last_unix'] = $now - 999999;
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $byId = array_column($r['rows'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::STALE, $byId['docs']['readiness']);
        $this->assertFalse($r['all_fresh']);
    }

    public function test_missing_source_row_yields_unknown(): void
    {
        $now = time();
        $sources = $this->freshSources($now);
        unset($sources['queue']);
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $byId = array_column($r['rows'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::UNKNOWN, $byId['queue']['readiness']);
    }

    public function test_empty_hash_yields_blocked(): void
    {
        $now = time();
        $sources = $this->freshSources($now);
        $sources['receipts']['hash'] = '';
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $byId = array_column($r['rows'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::BLOCKED, $byId['receipts']['readiness']);
        $this->assertSame('hash_missing', $byId['receipts']['reason']);
    }

    public function test_missing_last_unix_yields_blocked(): void
    {
        $now = time();
        $sources = $this->freshSources($now);
        unset($sources['runtime_evidence']['last_unix']);
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $byId = array_column($r['rows'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::BLOCKED, $byId['runtime_evidence']['readiness']);
        $this->assertSame('last_unix_missing', $byId['runtime_evidence']['reason']);
    }

    public function test_rows_sorted_byte_stably_by_source_id(): void
    {
        $now = time();
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $this->freshSources($now)]);
        $ids = array_column($r['rows'], 'source_id');
        $copy = $ids;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $ids);
    }

    public function test_two_calls_byte_identical_for_same_input(): void
    {
        $now = 1719252000;
        $b = new AtlasSelfConstructionCortexFreshnessBridge;
        $f = ['now_unix' => $now, 'sources' => $this->freshSources($now)];
        $this->assertSame(json_encode($b->adapt($f)), json_encode($b->adapt($f)));
    }

    public function test_future_last_unix_yields_blocked_with_future_timestamp_reason(): void
    {
        $now = time();
        $sources = $this->freshSources($now);
        $sources['docs']['last_unix'] = $now + 9999; // in the future
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $byId = array_column($r['rows'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::BLOCKED, $byId['docs']['readiness']);
        $this->assertSame('future_timestamp', $byId['docs']['reason']);
        $this->assertFalse($r['all_fresh']);
    }

    public function test_zero_freshness_window_yields_all_blocked_with_invalid_window_reason(): void
    {
        $now = time();
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => $now,
            'freshness_window_seconds' => 0,
            'sources' => $this->freshSources($now),
        ]);
        $this->assertFalse($r['all_fresh']);
        foreach ($r['rows'] as $row) {
            $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::BLOCKED, $row['readiness']);
            $this->assertSame('invalid_freshness_window', $row['reason']);
        }
    }

    public function test_negative_freshness_window_yields_all_blocked(): void
    {
        $now = time();
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => $now,
            'freshness_window_seconds' => -1,
            'sources' => $this->freshSources($now),
        ]);
        $this->assertFalse($r['all_fresh']);
        foreach ($r['rows'] as $row) {
            $this->assertSame(AtlasSelfConstructionCortexFreshnessBridge::BLOCKED, $row['readiness']);
        }
    }

    public function test_invalid_window_rows_are_sorted_by_source_id(): void
    {
        $now = time();
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => $now,
            'freshness_window_seconds' => 0,
            'sources' => $this->freshSources($now),
        ]);
        $ids = array_column($r['rows'], 'source_id');
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids);
    }

    // --- knowledge_dominance_refresh_plan tests ---

    public function test_all_fresh_yields_empty_refresh_plan_and_safe_to_origin_true(): void
    {
        $now = 1719252000;
        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix' => $now,
            'sources'  => $this->freshSources($now),
        ]);

        $this->assertArrayHasKey('knowledge_dominance_refresh_plan', $r);
        $this->assertSame([], $r['knowledge_dominance_refresh_plan']);
        $this->assertTrue($r['safe_to_origin_tasks']);
    }

    public function test_stale_source_produces_run_sync_plan_entry(): void
    {
        $now = 1719252000;
        $sources = $this->freshSources($now);
        $sources['docs']['last_unix'] = $now - 999999;

        $r    = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $plan = array_column($r['knowledge_dominance_refresh_plan'], null, 'source_id');

        $this->assertArrayHasKey('docs', $plan);
        $this->assertSame('run_sync', $plan['docs']['refresh_action']);
        $this->assertStringContainsString('age_', $plan['docs']['blocking_reason']);
        $this->assertSame('receipt:docs:run_sync', $plan['docs']['required_receipt']);
        $this->assertTrue($plan['docs']['safe_to_origin_tasks']);  // stale = still safe
    }

    public function test_unknown_source_produces_supply_source_plan_entry_not_safe(): void
    {
        $now = 1719252000;
        $sources = $this->freshSources($now);
        unset($sources['queue']);

        $r    = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $plan = array_column($r['knowledge_dominance_refresh_plan'], null, 'source_id');

        $this->assertArrayHasKey('queue', $plan);
        $this->assertSame('supply_source', $plan['queue']['refresh_action']);
        $this->assertSame('receipt:queue:supply_source', $plan['queue']['required_receipt']);
        $this->assertFalse($plan['queue']['safe_to_origin_tasks']);
    }

    public function test_blocked_hash_missing_produces_repair_hash_action(): void
    {
        $now = 1719252000;
        $sources = $this->freshSources($now);
        $sources['receipts']['hash'] = '';

        $r    = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $plan = array_column($r['knowledge_dominance_refresh_plan'], null, 'source_id');

        $this->assertSame('repair_hash', $plan['receipts']['refresh_action']);
        $this->assertFalse($plan['receipts']['safe_to_origin_tasks']);
    }

    public function test_blocked_future_timestamp_produces_correct_clock_action(): void
    {
        $now = 1719252000;
        $sources = $this->freshSources($now);
        $sources['docs']['last_unix'] = $now + 9999;

        $r    = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $plan = array_column($r['knowledge_dominance_refresh_plan'], null, 'source_id');

        $this->assertSame('correct_clock', $plan['docs']['refresh_action']);
        $this->assertFalse($plan['docs']['safe_to_origin_tasks']);
    }

    public function test_blocked_invalid_window_produces_repair_window_config_action(): void
    {
        $now = 1719252000;
        $r   = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt([
            'now_unix'                 => $now,
            'freshness_window_seconds' => 0,
            'sources'                  => $this->freshSources($now),
        ]);

        foreach ($r['knowledge_dominance_refresh_plan'] as $entry) {
            $this->assertSame('repair_window_config', $entry['refresh_action']);
            $this->assertFalse($entry['safe_to_origin_tasks']);
        }
        $this->assertCount(count(AtlasSelfConstructionCortexFreshnessBridge::REQUIRED_SOURCES), $r['knowledge_dominance_refresh_plan']);
    }

    public function test_plan_entry_has_all_required_keys(): void
    {
        $now = 1719252000;
        $sources = $this->freshSources($now);
        unset($sources['code_index']);

        $r     = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);
        $entry = $r['knowledge_dominance_refresh_plan'][0];

        foreach (['source_id', 'refresh_action', 'blocking_reason', 'required_receipt', 'safe_to_origin_tasks'] as $key) {
            $this->assertArrayHasKey($key, $entry);
        }
    }

    public function test_safe_to_origin_tasks_false_when_any_blocked_or_unknown(): void
    {
        $now = 1719252000;
        $sources = $this->freshSources($now);
        unset($sources['runtime_evidence']);

        $r = (new AtlasSelfConstructionCortexFreshnessBridge)->adapt(['now_unix' => $now, 'sources' => $sources]);

        $this->assertFalse($r['safe_to_origin_tasks']);
    }
}

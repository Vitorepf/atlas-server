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
}

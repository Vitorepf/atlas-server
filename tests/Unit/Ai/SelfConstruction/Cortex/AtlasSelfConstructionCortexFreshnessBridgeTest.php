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
}

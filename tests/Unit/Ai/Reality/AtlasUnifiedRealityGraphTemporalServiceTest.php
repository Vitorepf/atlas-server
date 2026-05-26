<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Reality;

use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Tests\TestCase;

class AtlasUnifiedRealityGraphTemporalServiceTest extends TestCase
{
    private string $tmpPath;

    private AtlasUnifiedRealityGraphTemporalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir().'/atlas_aurg_temporal_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasUnifiedRealityGraphTemporalService(new AtlasRealityGraphSnapshotBuilderService);
        $this->svc->setLogPathForTesting($this->tmpPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPath);
        parent::tearDown();
    }

    public function test_first_tick_has_null_prev_hash(): void
    {
        $t = $this->svc->recordTick(['kind' => 'rationale_event', 'rationale' => 'first']);
        $this->assertNull($t['prev_tick_hash']);
        $this->assertStringStartsWith('sha256:', $t['tick_hash']);
        $this->assertStringStartsWith('tick_', $t['tick_id']);
    }

    public function test_second_tick_chains_to_first(): void
    {
        $a = $this->svc->recordTick(['kind' => 'rationale_event', 'rationale' => 'a']);
        usleep(1100000); // ensure distinct ISO timestamps (1.1s)
        $b = $this->svc->recordTick(['kind' => 'rationale_event', 'rationale' => 'b']);
        $this->assertSame($a['tick_hash'], $b['prev_tick_hash']);
    }

    public function test_unknown_kind_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordTick(['kind' => 'not_a_kind']);
    }

    public function test_unknown_actor_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordTick(['kind' => 'rationale_event', 'actor' => 'martian']);
    }

    public function test_timeline_envelope_shape(): void
    {
        $this->svc->recordTick(['kind' => 'rationale_event']);
        $tl = $this->svc->timeline();
        $this->assertSame(AtlasUnifiedRealityGraphTemporalService::SNAPSHOT_SCHEMA, $tl['schema_version']);
        $this->assertSame(1, $tl['tick_count']);
        $this->assertTrue($tl['chain_intact']);
        $this->assertStringStartsWith('sha256:', $tl['temporal_hash']);
    }

    public function test_verify_chain_detects_break(): void
    {
        $this->svc->recordTick(['kind' => 'rationale_event']);
        // Corrupt the chain by directly writing a tampered tick.
        $bad = json_encode([
            'schema_version' => AtlasUnifiedRealityGraphTemporalService::TICK_SCHEMA,
            'tick_id' => 'tick_bad',
            'at' => date('c', time() + 10),
            'actor' => 'operator',
            'kind' => 'rationale_event',
            'snapshot_hash' => null,
            'delta_summary' => [],
            'rationale' => '',
            'prev_tick_hash' => 'sha256:WRONG',
            'tick_hash' => 'sha256:also_wrong',
        ]);
        file_put_contents($this->tmpPath, "\n".$bad, FILE_APPEND);
        $r = $this->svc->verifyChain();
        $this->assertFalse($r['chain_intact']);
        $this->assertSame('tick_bad', $r['chain_break_at']);
    }

    public function test_state_at_returns_null_when_before_first(): void
    {
        $this->svc->recordTick(['kind' => 'rationale_event']);
        $this->assertNull($this->svc->stateAt('2000-01-01T00:00:00Z'));
    }

    public function test_state_at_returns_most_recent_before_target(): void
    {
        $a = $this->svc->recordTick(['kind' => 'rationale_event', 'rationale' => 'a']);
        $target = date('c', time() + 5);
        $best = $this->svc->stateAt($target);
        $this->assertSame($a['tick_id'], $best['tick_id']);
    }

    public function test_traverse_time_bounded(): void
    {
        $this->svc->recordTick(['kind' => 'rationale_event', 'rationale' => 'a']);
        $ticks = $this->svc->traverseTime('1970-01-01T00:00:00Z', date('c', time() + 100), 5);
        $this->assertLessThanOrEqual(5, count($ticks));
    }

    public function test_invalid_iso_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->stateAt('not-iso');
    }

    public function test_capture_snapshot_records_tick_with_snapshot_hash(): void
    {
        $r = $this->svc->captureSnapshot(
            [['id' => 'a', 'kind' => 'doc']],
            [],
            'operator',
            'capture-test'
        );
        $this->assertArrayHasKey('snapshot', $r);
        $this->assertArrayHasKey('tick', $r);
        $this->assertSame($r['snapshot']['snapshot_hash'], $r['tick']['snapshot_hash']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricMalformedZeroRegressionSentinel;
use Tests\TestCase;

final class AtlasTaskFabricMalformedZeroRegressionSentinelTest extends TestCase
{
    private function svc(): AtlasTaskFabricMalformedZeroRegressionSentinel
    {
        return new AtlasTaskFabricMalformedZeroRegressionSentinel;
    }

    private function clean(): array
    {
        return [
            'health' => ['healthy' => true, 'malformed_count' => 0],
            'sweep' => ['would_block_count' => 0, 'malformed_count' => 0],
            'queued_targets' => ['collision_count' => 0, 'dry_queue' => false, 'recoverable_count' => 0],
        ];
    }

    // ── accepted ──────────────────────────────────────────────────────────────

    public function test_all_clean_snapshot_is_accepted(): void
    {
        $r = $this->svc()->score($this->clean());

        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['rejection_reasons']);
        $this->assertSame(AtlasTaskFabricMalformedZeroRegressionSentinel::SCHEMA, $r['schema_version']);
    }

    // ── individual rejection signals ──────────────────────────────────────────

    public function test_health_not_ok_rejects(): void
    {
        $s = $this->clean();
        $s['health']['healthy'] = false;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('health_not_ok', $r['rejection_reasons']);
    }

    public function test_malformed_count_nonzero_rejects(): void
    {
        $s = $this->clean();
        $s['health']['malformed_count'] = 2;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('malformed_count_nonzero', $r['rejection_reasons']);
    }

    public function test_would_block_count_nonzero_rejects(): void
    {
        $s = $this->clean();
        $s['sweep']['would_block_count'] = 1;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('would_block_count_nonzero', $r['rejection_reasons']);
    }

    public function test_sweep_malformed_nonzero_rejects(): void
    {
        $s = $this->clean();
        $s['sweep']['malformed_count'] = 3;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('sweep_malformed_nonzero', $r['rejection_reasons']);
    }

    public function test_collision_count_nonzero_rejects(): void
    {
        $s = $this->clean();
        $s['queued_targets']['collision_count'] = 1;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('collision_count_nonzero', $r['rejection_reasons']);
    }

    public function test_dry_queue_rejects(): void
    {
        $s = $this->clean();
        $s['queued_targets']['dry_queue'] = true;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('dry_queue', $r['rejection_reasons']);
    }

    public function test_recoverable_backlog_rejects(): void
    {
        $s = $this->clean();
        $s['queued_targets']['recoverable_count'] = 5;
        $r = $this->svc()->score($s);

        $this->assertFalse($r['accepted']);
        $this->assertContains('recoverable_backlog_nonzero', $r['rejection_reasons']);
    }

    // ── multi-signal & sort ───────────────────────────────────────────────────

    public function test_multiple_issues_all_reported_and_sorted(): void
    {
        $r = $this->svc()->score([
            'health' => ['healthy' => false, 'malformed_count' => 1],
            'sweep' => ['would_block_count' => 2, 'malformed_count' => 0],
            'queued_targets' => ['collision_count' => 1, 'dry_queue' => true, 'recoverable_count' => 3],
        ]);

        $this->assertFalse($r['accepted']);
        $sorted = $r['rejection_reasons'];
        $copy = $sorted;
        sort($copy);
        $this->assertSame($copy, $sorted, 'rejection_reasons must be sorted');
        $this->assertContains('health_not_ok', $sorted);
        $this->assertContains('malformed_count_nonzero', $sorted);
        $this->assertContains('would_block_count_nonzero', $sorted);
        $this->assertContains('collision_count_nonzero', $sorted);
        $this->assertContains('dry_queue', $sorted);
        $this->assertContains('recoverable_backlog_nonzero', $sorted);
    }

    public function test_empty_snapshot_defaults_to_accepted(): void
    {
        $r = $this->svc()->score([]);

        $this->assertTrue($r['accepted']);
    }
}

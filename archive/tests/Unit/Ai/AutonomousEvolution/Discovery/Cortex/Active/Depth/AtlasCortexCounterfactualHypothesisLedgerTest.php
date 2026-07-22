<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualHypothesisLedger;
use RuntimeException;
use Tests\TestCase;

final class AtlasCortexCounterfactualHypothesisLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-hypothesis-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasCortexCounterfactualHypothesisLedger::setRootForTesting($this->root);
        AtlasCortexCounterfactualHypothesisLedger::$flagOverride = true;
    }

    protected function tearDown(): void
    {
        AtlasCortexCounterfactualHypothesisLedger::setRootForTesting(null);
        AtlasCortexCounterfactualHypothesisLedger::$flagOverride = null;
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'walk_id' => 'walk-1',
            'snapshot_sha' => 'snap-A',
            'steps_json' => [['edge' => 'a->b'], ['edge' => 'b->c']],
            'terminated_reason' => 'max_depth',
            'observed_at_utc' => '2026-06-25T12:00:00Z',
            'site_id' => 'site-7',
        ];
    }

    public function test_recording_same_hypothesis_twice_yields_single_row(): void
    {
        $ledger = new AtlasCortexCounterfactualHypothesisLedger();
        $first = $ledger->record($this->payload());
        $second = $ledger->record($this->payload());

        $this->assertSame($first['hypothesis_id'], $second['hypothesis_id']);
        $rows = $ledger->all();
        $this->assertCount(1, $rows);
    }

    public function test_attempting_to_mutate_existing_row_throws(): void
    {
        $ledger = new AtlasCortexCounterfactualHypothesisLedger();
        $row = $ledger->record($this->payload());
        // Plant a tampered file at the row's path with a different hypothesis_id.
        $path = $this->root.'/'.$row['hypothesis_id'].'.json';
        file_put_contents($path, json_encode(['hypothesis_id' => 'tampered-id']));

        $this->expectException(RuntimeException::class);
        $ledger->record($this->payload());
    }

    public function test_payload_with_forbidden_score_grade_rating_quality_keys_is_rejected(): void
    {
        $ledger = new AtlasCortexCounterfactualHypothesisLedger();
        foreach (['score', 'grade', 'rating', 'quality'] as $forbidden) {
            try {
                $ledger->record($this->payload([$forbidden => 0.9]));
                $this->fail("expected exception for key {$forbidden}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('forbidden_key', $e->getMessage());
            }
        }
    }

    public function test_flag_off_makes_record_a_noop(): void
    {
        AtlasCortexCounterfactualHypothesisLedger::$flagOverride = false;
        $ledger = new AtlasCortexCounterfactualHypothesisLedger();
        $result = $ledger->record($this->payload());
        $this->assertNull($result);
        $this->assertSame([], $ledger->all());
    }

    public function test_history_for_walk_and_site_filters(): void
    {
        $ledger = new AtlasCortexCounterfactualHypothesisLedger();
        $ledger->record($this->payload(['walk_id' => 'walk-A', 'site_id' => 'site-1']));
        $ledger->record($this->payload(['walk_id' => 'walk-A', 'site_id' => 'site-2', 'snapshot_sha' => 'snap-B']));
        $ledger->record($this->payload(['walk_id' => 'walk-B', 'site_id' => 'site-1', 'snapshot_sha' => 'snap-C']));

        $this->assertCount(2, $ledger->historyForWalk('walk-A'));
        $this->assertCount(2, $ledger->historyForSite('site-1'));
    }
}

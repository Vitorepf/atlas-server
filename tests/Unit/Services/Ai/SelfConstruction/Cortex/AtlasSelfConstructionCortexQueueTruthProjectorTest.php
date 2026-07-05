<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexQueueTruthProjector;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCortexQueueTruthProjectorTest extends TestCase
{
    private AtlasSelfConstructionCortexQueueTruthProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new AtlasSelfConstructionCortexQueueTruthProjector;
    }

    // ── AC: claimable, claimed, recoverable, malformed and collision facts are preserved ──

    public function test_queue_facts_are_preserved(): void
    {
        $result = $this->projector->project([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'claimable_depth' => 12,
                'claimed_records' => 3,
                'active_leases' => 3,
                'recoverable_candidates_total' => 1,
                'malformed_count' => 2,
                'collision_count' => 1,
                'healthy' => false,
                'lease_leak_detected' => true,
                'dry_queue' => false,
            ],
        ]);

        $this->assertSame(12, $result['truth']['claimable']);
        $this->assertSame(3, $result['truth']['claimed']);
        $this->assertSame(1, $result['truth']['recoverable']);
        $this->assertSame(2, $result['truth']['malformed']);
        $this->assertSame(1, $result['truth']['collisions']);
        $this->assertFalse($result['truth']['healthy']);
        $this->assertTrue($result['truth']['lease_leak']);
    }

    // ── AC: no raw prompts in output ──

    public function test_output_contains_no_prompts(): void
    {
        $result = $this->projector->project([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'claimable_depth' => 5,
                'prompt' => 'this should not leak',
                'objective' => 'secret objective',
            ],
        ]);

        $this->assertArrayNotHasKey('prompt', $result['truth']);
        $this->assertArrayNotHasKey('objective', $result['truth']);
    }

    // ── signals ──

    public function test_healthy_queue_emits_ok_signal(): void
    {
        $result = $this->projector->project([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'claimable_depth' => 5,
                'healthy' => true,
            ],
        ]);

        $this->assertContains('queue_ok', $result['signals']);
    }

    public function test_malformed_emits_signal(): void
    {
        $result = $this->projector->project([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'malformed_count' => 1,
            ],
        ]);

        $this->assertContains('malformed_present', $result['signals']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->projector->project([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [],
        ]);

        $this->assertSame(AtlasSelfConstructionCortexQueueTruthProjector::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('truth', $result);
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('compact_hash', $result);
        $this->assertStringStartsWith('qtp_', $result['compact_hash']);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'claimable_depth' => 7,
                'malformed_count' => 1,
            ],
        ];

        $a = $this->projector->project($input);
        $b = $this->projector->project($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}

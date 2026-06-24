<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V4;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4CapabilityDeltaAttribution;
use PHPUnit\Framework\TestCase;

/**
 * Proves the V4 capability-delta attribution engine: schema-drift + zero-movement refusals, correct per-dim
 * deltas, stable shared-credit ordering, confound surfacing, and the attributed=true happy path.
 */
final class AtlasLoopV4CapabilityDeltaAttributionTest extends TestCase
{
    private function engine(): AtlasLoopV4CapabilityDeltaAttribution
    {
        return new AtlasLoopV4CapabilityDeltaAttribution;
    }

    public function test_refuses_on_snapshot_schema_drift(): void
    {
        $out = $this->engine()->attribute(['a' => 1.0, 'b' => 2.0], ['a' => 1.0, 'c' => 2.0], []);

        $this->assertSame('snapshot_schema_drift', $out['refuse_reason']);
        $this->assertFalse($out['attributed']);
    }

    public function test_refuses_on_zero_movement(): void
    {
        $out = $this->engine()->attribute(['a' => 1.0, 'b' => 2.0], ['a' => 1.0, 'b' => 2.0], [
            ['proposal_id' => 'p1', 'target_dimension' => 'a'],
        ]);

        $this->assertSame('zero_movement', $out['refuse_reason']);
        $this->assertFalse($out['attributed']);
    }

    public function test_per_dim_delta_equals_post_minus_pre(): void
    {
        $out = $this->engine()->attribute(['a' => 1.0], ['a' => 3.0], [['proposal_id' => 'p1', 'target_dimension' => 'a']]);

        $this->assertSame(1.0, $out['deltas']['a']['pre']);
        $this->assertSame(3.0, $out['deltas']['a']['post']);
        $this->assertSame(2.0, $out['deltas']['a']['delta']);
    }

    public function test_shared_credit_listed_in_stable_proposal_id_order(): void
    {
        $out = $this->engine()->attribute(['a' => 0.0], ['a' => 1.0], [
            ['proposal_id' => 'p2', 'target_dimension' => 'a'],
            ['proposal_id' => 'p1', 'target_dimension' => 'a'],
        ]);

        $this->assertSame(['p1', 'p2'], $out['deltas']['a']['attributed_to'], 'co-claimants in proposal_id order, not divided');
        $this->assertTrue($out['attributed']);
    }

    public function test_unattributed_dimension_surfaces_confound(): void
    {
        $out = $this->engine()->attribute(
            ['a' => 0.0, 'b' => 0.0],
            ['a' => 1.0, 'b' => 5.0],
            [['proposal_id' => 'p1', 'target_dimension' => 'a']], // nobody claims 'b'
        );

        $this->assertSame(['b'], $out['unattributed_dimensions']);
        $this->assertSame(['p1'], $out['deltas']['a']['attributed_to']);
        $this->assertTrue($out['attributed'], 'a moved and was claimed');
    }

    public function test_attributed_false_when_only_unclaimed_movement(): void
    {
        $out = $this->engine()->attribute(['a' => 0.0], ['a' => 1.0], []); // moved but nobody claimed it

        $this->assertFalse($out['attributed']);
        $this->assertSame(['a'], $out['unattributed_dimensions']);
        $this->assertNull($out['refuse_reason']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V4;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4ObjectiveToWorkBridge;
use PHPUnit\Framework\TestCase;

final class AtlasLoopV4ObjectiveToWorkBridgeTest extends TestCase
{
    public function test_refuses_non_originated_upstream(): void
    {
        $result = $this->bridge()->bridge([
            'originated' => false,
            'objective' => 'Focus AtlasLoopV4MetaObjectiveOriginator.',
            'target_metric' => 'origination',
            'target_delta' => 0.5,
        ], ['AtlasLoopV4MetaObjectiveOriginator']);

        $this->assertFalse($result['bridged']);
        $this->assertSame([], $result['scoped_inventory']);
        $this->assertNull($result['leverage_hint']);
        $this->assertSame('upstream_not_originated', $result['refuse_reason']);
    }

    public function test_refuses_when_objective_has_no_inventory_intersection(): void
    {
        $result = $this->bridge()->bridge($this->metaObjective([
            'objective' => 'Raise grounded recall by focusing GhostSymbol and MissingScope.',
        ]), ['AtlasLoopV4MetaObjectiveOriginator']);

        $this->assertFalse($result['bridged']);
        $this->assertSame([], $result['scoped_inventory']);
        $this->assertSame('no_scoped_inventory', $result['refuse_reason']);
    }

    public function test_honours_inventory_intersection_only(): void
    {
        $result = $this->bridge()->bridge($this->metaObjective([
            'objective' => 'Focus AtlasLoopV4MetaObjectiveOriginator, GhostSymbol, and AtlasLoopV4ObjectiveToWorkBridge.',
        ]), [
            'AtlasLoopV4ObjectiveToWorkBridge',
            'AtlasLoopV4MetaObjectiveOriginator',
            'UnmentionedInventoryMember',
        ]);

        $this->assertTrue($result['bridged']);
        $this->assertSame([
            'AtlasLoopV4MetaObjectiveOriginator',
            'AtlasLoopV4ObjectiveToWorkBridge',
        ], $result['scoped_inventory']);
        $this->assertNotContains('GhostSymbol', $result['scoped_inventory']);
        $this->assertNotContains('UnmentionedInventoryMember', $result['scoped_inventory']);
    }

    public function test_leverage_hint_format_is_exact(): void
    {
        $result = $this->bridge()->bridge($this->metaObjective([
            'target_metric' => 'delivery',
            'target_delta' => 0.75,
        ]), ['AtlasLoopV4MetaObjectiveOriginator']);

        $this->assertTrue($result['bridged']);
        $this->assertSame('delivery:+0.75', $result['leverage_hint']);
        $this->assertNull($result['refuse_reason']);
    }

    private function bridge(): AtlasLoopV4ObjectiveToWorkBridge
    {
        return new AtlasLoopV4ObjectiveToWorkBridge;
    }

    /** @return array<string,mixed> */
    private function metaObjective(array $overrides = []): array
    {
        return array_merge([
            'originated' => true,
            'objective' => 'Raise grounded recall by focusing AtlasLoopV4MetaObjectiveOriginator.',
            'target_metric' => 'origination',
            'target_delta' => 0.5,
            'cited_facts' => ['origination.refuted_inventory=4'],
            'reason' => null,
        ], $overrides);
    }
}

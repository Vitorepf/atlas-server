<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeWeightedVeinSelector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeWeightedVeinSelectorTest extends TestCase
{
    private AtlasExternalBrainOutcomeWeightedVeinSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new AtlasExternalBrainOutcomeWeightedVeinSelector;
    }

    // ── AC: successful veins are reinforced ──

    public function test_successful_vein_is_reinforced(): void
    {
        $result = $this->selector->select([
            ['vein_id' => AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_TASK_FABRIC, 'result' => 'success'],
            ['vein_id' => AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_TASK_FABRIC, 'result' => 'success'],
        ], [], AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_EXTERNAL_BRAIN);

        $this->assertSame(AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_TASK_FABRIC, $result['next_vein']);
        $this->assertTrue($result['changed']);
        $this->assertGreaterThan(0.5, $result['vein_scores'][AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_TASK_FABRIC]);
    }

    // ── AC: failing veins cool down ──

    public function test_failing_vein_cools_down(): void
    {
        $result = $this->selector->select([
            ['vein_id' => AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_MAESTRO, 'result' => 'blocked'],
        ], [], AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_EXTERNAL_BRAIN);

        $this->assertNotSame(AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_MAESTRO, $result['next_vein']);
        $this->assertLessThan(0.5, $result['vein_scores'][AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_MAESTRO]);
    }

    // ── AC: neglected high-leverage veins receive priority ──

    public function test_neglected_high_leverage_vein_receives_priority(): void
    {
        $result = $this->selector->select([], [
            ['vein_id' => AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_EXTERNAL_BRAIN, 'saturation' => 0.0, 'leverage_score' => 0.6, 'last_rotated_at' => 10],
            ['vein_id' => AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_ANTI_GOODHART, 'saturation' => 0.0, 'leverage_score' => 0.9, 'last_rotated_at' => 1],
        ], AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_EXTERNAL_BRAIN);

        $this->assertSame(AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_ANTI_GOODHART, $result['next_vein']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->selector->select([], [], null);

        $this->assertSame(AtlasExternalBrainOutcomeWeightedVeinSelector::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('next_vein', $result);
        $this->assertArrayHasKey('vein_scores', $result);
        $this->assertArrayHasKey('outcome_counts', $result);
        $this->assertArrayHasKey('selected_reason', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            ['vein_id' => AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_LEARNING_LOOP, 'result' => 'success'],
        ];

        $a = $this->selector->select($input, [], AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_EXTERNAL_BRAIN);
        $b = $this->selector->select($input, [], AtlasExternalBrainOutcomeWeightedVeinSelector::VEIN_EXTERNAL_BRAIN);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}

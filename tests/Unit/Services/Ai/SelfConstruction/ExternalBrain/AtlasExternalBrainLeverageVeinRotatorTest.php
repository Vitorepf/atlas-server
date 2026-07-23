<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLeverageVeinRotator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLeverageVeinRotatorTest extends TestCase
{
    private AtlasExternalBrainLeverageVeinRotator $rotator;

    protected function setUp(): void
    {
        $this->rotator = new AtlasExternalBrainLeverageVeinRotator;
    }

    public function test_sufficient_depth_never_stops_rotation(): void
    {
        $result = $this->rotator->rotate([], null);

        $this->assertFalse($result['sufficient_depth_stops_rotation']);
        $this->assertNotNull($result['next_vein']);
    }

    public function test_saturated_veins_are_cooled_down(): void
    {
        $result = $this->rotator->rotate([
            ['vein_id' => AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN, 'saturation' => 0.90, 'leverage_score' => 0.9, 'last_rotated_at' => 1],
            ['vein_id' => AtlasExternalBrainLeverageVeinRotator::VEIN_SELF_CONSTRUCTION, 'saturation' => 0.10, 'leverage_score' => 0.5, 'last_rotated_at' => 2],
        ], AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN);

        $this->assertContains(AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN, $result['saturated_veins']);
        $this->assertNotSame(AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN, $result['next_vein']);
    }

    public function test_underrepresented_high_leverage_vein_selected_next(): void
    {
        $result = $this->rotator->rotate([
            ['vein_id' => AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN, 'saturation' => 0.10, 'leverage_score' => 0.3, 'last_rotated_at' => 1],
            ['vein_id' => AtlasExternalBrainLeverageVeinRotator::VEIN_TASK_FABRIC, 'saturation' => 0.10, 'leverage_score' => 0.9, 'last_rotated_at' => 2],
        ], AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN);

        $this->assertSame(AtlasExternalBrainLeverageVeinRotator::VEIN_TASK_FABRIC, $result['next_vein']);
    }

    public function test_current_vein_excluded_when_alternatives_exist(): void
    {
        $result = $this->rotator->rotate([], AtlasExternalBrainLeverageVeinRotator::VEIN_MAESTRO);

        $this->assertNotSame(AtlasExternalBrainLeverageVeinRotator::VEIN_MAESTRO, $result['next_vein']);
        $this->assertTrue($result['rotated']);
    }

    public function test_all_saturated_picks_least_saturated(): void
    {
        $stats = [];
        foreach (AtlasExternalBrainLeverageVeinRotator::ALL_VEINS as $i => $vein) {
            $stats[] = ['vein_id' => $vein, 'saturation' => 0.90 + ($i * 0.01), 'leverage_score' => 0.5, 'last_rotated_at' => $i];
        }

        $result = $this->rotator->rotate($stats, null);

        $this->assertNotNull($result['next_vein']);
        // The least saturated is the first vein (0.90)
        $this->assertSame(AtlasExternalBrainLeverageVeinRotator::VEIN_EXTERNAL_BRAIN, $result['next_vein']);
    }

    public function test_schema_present(): void
    {
        $result = $this->rotator->rotate([], null);
        $this->assertSame(AtlasExternalBrainLeverageVeinRotator::SCHEMA, $result['schema']);
    }
}

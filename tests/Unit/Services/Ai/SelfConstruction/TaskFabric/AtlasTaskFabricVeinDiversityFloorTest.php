<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricVeinDiversityFloor;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricVeinDiversityFloorTest extends TestCase
{
    private AtlasTaskFabricVeinDiversityFloor $floor;

    protected function setUp(): void
    {
        $this->floor = new AtlasTaskFabricVeinDiversityFloor;
    }

    public function test_overrepresented_veins_are_capped(): void
    {
        $tasks = [];
        for ($i = 0; $i < 6; $i++) {
            $tasks[] = ['vein' => 'external_brain'];
        }
        $tasks[] = ['vein' => 'self_construction'];
        $tasks[] = ['vein' => 'maestro'];
        $tasks[] = ['vein' => 'autonomy'];
        $tasks[] = ['vein' => 'learning_loop'];

        $result = $this->floor->evaluate($tasks);

        $this->assertNotEmpty($result['capped_veins']);
    }

    public function test_missing_high_leverage_veins_are_requested(): void
    {
        $tasks = [];
        foreach (['external_brain', 'self_construction', 'task_fabric', 'maestro', 'learning_loop'] as $vein) {
            $tasks[] = ['vein' => $vein];
        }

        $result = $this->floor->evaluate($tasks);

        $this->assertNotEmpty($result['missing_veins']);
        $this->assertContains('autonomy', $result['missing_veins']);
        $this->assertContains('anti_goodhart', $result['missing_veins']);
    }

    public function test_diverse_batch_of_5_passes(): void
    {
        $tasks = [];
        foreach (['external_brain', 'self_construction', 'task_fabric', 'maestro', 'autonomy'] as $vein) {
            $tasks[] = ['vein' => $vein];
        }

        $result = $this->floor->evaluate($tasks);

        $this->assertTrue($result['batch_valid']);
        $this->assertGreaterThanOrEqual(AtlasTaskFabricVeinDiversityFloor::MIN_DIVERSITY_FRACTION, $result['diversity_score']);
    }

    public function test_batch_too_small_fails(): void
    {
        $result = $this->floor->evaluate([['vein' => 'external_brain']]);

        $this->assertFalse($result['batch_valid']);
        $this->assertFalse($result['diverse']);
    }

    public function test_batch_too_large_fails(): void
    {
        $tasks = [];
        for ($i = 0; $i < 13; $i++) {
            $tasks[] = ['vein' => self::VEINS[$i % count(self::VEINS)]];
        }

        $result = $this->floor->evaluate($tasks);

        $this->assertFalse($result['batch_valid']);
    }

    private const VEINS = [
        'external_brain', 'self_construction', 'task_fabric',
        'maestro', 'learning_loop', 'autonomy', 'anti_goodhart',
    ];

    public function test_schema_present(): void
    {
        $result = $this->floor->evaluate([]);
        $this->assertSame(AtlasTaskFabricVeinDiversityFloor::SCHEMA, $result['schema']);
    }
}

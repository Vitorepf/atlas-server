<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Fairness;

use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroTaskFamilyFairnessFloor;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroTaskFamilyFairnessFloorTest extends TestCase
{
    private AtlasMaestroTaskFamilyFairnessFloor $floor;

    protected function setUp(): void
    {
        $this->floor = new AtlasMaestroTaskFamilyFairnessFloor;
    }

    // ── AC: overrepresented families are cooled down ──

    public function test_overrepresented_family_is_cooled_down(): void
    {
        $result = $this->floor->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'family_counts' => [
                'repair' => 80,
                'learning' => 5,
                'autonomy' => 5,
                'simplification' => 5,
                'verification' => 5,
            ],
        ]);

        $this->assertContains('repair', $result['cooled_families']);
        $actions = array_column($result['actions'], 'action', 'family');
        $this->assertSame(AtlasMaestroTaskFamilyFairnessFloor::ACTION_COOL_DOWN, $actions['repair']);
    }

    // ── AC: underrepresented critical families are promoted ──

    public function test_underrepresented_family_is_promoted(): void
    {
        $result = $this->floor->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'family_counts' => [
                'repair' => 80,
                'learning' => 5,
                'autonomy' => 5,
                'simplification' => 5,
                'verification' => 5,
            ],
        ]);

        $this->assertContains('learning', $result['promoted_families']);
        $this->assertContains('autonomy', $result['promoted_families']);
        $this->assertContains('simplification', $result['promoted_families']);
        $this->assertContains('verification', $result['promoted_families']);
    }

    public function test_balanced_families_are_held(): void
    {
        $result = $this->floor->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'family_counts' => [
                'repair' => 20,
                'learning' => 20,
                'autonomy' => 20,
                'simplification' => 20,
                'verification' => 20,
            ],
        ]);

        $this->assertSame([], $result['promoted_families']);
        $this->assertSame([], $result['cooled_families']);
        $actions = array_column($result['actions'], 'action');
        $this->assertContains(AtlasMaestroTaskFamilyFairnessFloor::ACTION_HOLD, $actions);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->floor->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'family_counts' => [],
        ]);

        $this->assertSame(AtlasMaestroTaskFamilyFairnessFloor::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('family_shares', $result);
        $this->assertArrayHasKey('actions', $result);
        $this->assertArrayHasKey('promoted_families', $result);
        $this->assertArrayHasKey('cooled_families', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'family_counts' => [
                'repair' => 10,
                'learning' => 10,
                'autonomy' => 10,
                'simplification' => 10,
                'verification' => 10,
            ],
        ];

        $a = $this->floor->evaluate($input);
        $b = $this->floor->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}

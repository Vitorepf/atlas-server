<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAgeAwareAdmissionPenalty;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskFabricAgeAwareAdmissionPenalty::saturationReasons flags
 * saturation when there are zero active workers and claimable items.
 */
final class AtlasTaskFabricAgeAwareAdmissionPenaltyHardeningTest extends TestCase
{
    private function saturationReasons(
        AtlasTaskFabricAgeAwareAdmissionPenalty $penalty,
        array $queueFacts,
        int $claimableDepth
    ): array {
        $reflection = new \ReflectionClass($penalty);
        $method = $reflection->getMethod('saturationReasons');
        return $method->invoke($penalty, $queueFacts, $claimableDepth);
    }

    public function test_zero_workers_with_claimable_items_is_saturated(): void
    {
        $penalty = new AtlasTaskFabricAgeAwareAdmissionPenalty();

        $reasons = $this->saturationReasons($penalty, [
            'worker_consumption' => [
                'active_workers' => 0,
            ],
        ], 10); // 10 claimable items

        $this->assertNotEmpty($reasons);
        $found = false;
        foreach ($reasons as $reason) {
            if (str_contains($reason, 'claimable_per_active_worker')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Expected claimable_per_active_worker saturation reason for zero workers");
    }

    public function test_zero_workers_with_zero_claimable_is_not_saturated(): void
    {
        $penalty = new AtlasTaskFabricAgeAwareAdmissionPenalty();

        $reasons = $this->saturationReasons($penalty, [
            'worker_consumption' => [
                'active_workers' => 0,
            ],
        ], 0); // 0 claimable items

        $found = false;
        foreach ($reasons as $reason) {
            if (str_contains($reason, 'claimable_per_active_worker')) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, "Should not flag saturation when no claimable items");
    }

    public function test_normal_workers_computes_ratio_correctly(): void
    {
        $penalty = new AtlasTaskFabricAgeAwareAdmissionPenalty();

        // 100 claimable / 2 workers = 50 per worker (above threshold)
        $reasons = $this->saturationReasons($penalty, [
            'worker_consumption' => [
                'active_workers' => 2,
            ],
        ], 100);

        $found = false;
        foreach ($reasons as $reason) {
            if (str_contains($reason, 'claimable_per_active_worker')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Expected saturation reason for high claimable per worker");
    }
}

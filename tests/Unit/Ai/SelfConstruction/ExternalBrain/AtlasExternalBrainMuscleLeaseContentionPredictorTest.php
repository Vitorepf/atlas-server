<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleLeaseContentionPredictor;
use PHPUnit\Framework\TestCase;

class AtlasExternalBrainMuscleLeaseContentionPredictorTest extends TestCase
{
    private function svc(): AtlasExternalBrainMuscleLeaseContentionPredictor
    {
        return new AtlasExternalBrainMuscleLeaseContentionPredictor();
    }

    // ── fileFamilyPressure: safe_parallelism, bottleneck_reason, recommended_worker_mix, file_family_pressure ──

    public function test_balanced_families_low_pressure(): void
    {
        $r = $this->svc()->fileFamilyPressure([
            'file_families' => ['maestro', 'maestro', 'external_brain', 'external_brain'],
            'active_workers' => 2,
        ]);
        $this->assertSame(2, $r['safe_parallelism']);
        $this->assertSame('none', $r['bottleneck_reason']);
        $this->assertSame('maintain_2_workers', $r['recommended_worker_mix']);
        $this->assertSame(1.0, $r['file_family_pressure']);
    }

    public function test_dominant_family_blocks_adding_muscle(): void
    {
        $r = $this->svc()->fileFamilyPressure([
            'file_families' => ['maestro', 'maestro', 'maestro', 'external_brain'],
            'active_workers' => 3,
        ]);
        $this->assertSame(2, $r['safe_parallelism']);
        $this->assertStringContainsString('family_dominance', $r['bottleneck_reason']);
        $this->assertStringContainsString('reduce_workers_on_maestro_to_1', $r['recommended_worker_mix']);
        $this->assertGreaterThan(1.0, $r['file_family_pressure']);
    }

    public function test_workers_exceed_safe_parallelism(): void
    {
        $r = $this->svc()->fileFamilyPressure([
            'file_families' => ['a', 'b', 'c'],
            'active_workers' => 5,
        ]);
        $this->assertSame(3, $r['safe_parallelism']);
        $this->assertSame('worker_count_exceeds_safe_parallelism', $r['bottleneck_reason']);
        $this->assertSame('scale_workers_to_3', $r['recommended_worker_mix']);
        $this->assertSame(1.6667, $r['file_family_pressure']);
    }

    public function test_empty_families_no_pressure(): void
    {
        $r = $this->svc()->fileFamilyPressure([
            'file_families' => [],
            'active_workers' => 0,
        ]);
        $this->assertSame(1, $r['safe_parallelism']);
        $this->assertSame('none', $r['bottleneck_reason']);
        $this->assertSame('maintain_0_workers', $r['recommended_worker_mix']);
        $this->assertSame(0.0, $r['file_family_pressure']);
    }
}

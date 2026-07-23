<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleLeaseContentionPredictor;
use Tests\TestCase;

final class AtlasExternalBrainMuscleLeaseContentionPredictorTest extends TestCase
{
    public function test_workers_below_distinct_file_families_is_low_contention_and_safe_to_add(): void
    {
        $result = (new AtlasExternalBrainMuscleLeaseContentionPredictor)->predict([
            'active_leases' => 2,
            'claimed_records' => 2,
            'workers' => 2,
            'file_families' => ['FooService', 'BarService', 'BazService', 'QuxService'],
        ]);

        $this->assertSame('low', $result['contention_risk']);
        $this->assertTrue($result['safe_to_add_another_muscle']);
        $this->assertSame('none', $result['bottleneck_reason']);
        $this->assertSame(4, $result['facts']['distinct_file_families']);
    }

    public function test_workers_exceeding_distinct_file_families_is_high_contention(): void
    {
        $result = (new AtlasExternalBrainMuscleLeaseContentionPredictor)->predict([
            'active_leases' => 5,
            'claimed_records' => 5,
            'workers' => 5,
            'file_families' => ['FooService', 'FooService', 'BarService'],
        ]);

        $this->assertSame('high', $result['contention_risk']);
        $this->assertFalse($result['safe_to_add_another_muscle']);
        $this->assertSame('worker_count_exceeds_file_family_parallelism', $result['bottleneck_reason']);
        $this->assertSame(2, $result['facts']['distinct_file_families']);
        $this->assertLessThanOrEqual(2, $result['recommended_worker_count']);
    }

    public function test_workers_equal_to_distinct_file_families_is_medium_contention(): void
    {
        $result = (new AtlasExternalBrainMuscleLeaseContentionPredictor)->predict([
            'active_leases' => 3,
            'claimed_records' => 3,
            'workers' => 3,
            'file_families' => ['FooService', 'BarService', 'BazService'],
        ]);

        $this->assertSame('medium', $result['contention_risk']);
        $this->assertFalse($result['safe_to_add_another_muscle']);
    }

    public function test_recent_index_lock_events_force_high_contention_regardless_of_family_count(): void
    {
        $result = (new AtlasExternalBrainMuscleLeaseContentionPredictor)->predict([
            'active_leases' => 1,
            'claimed_records' => 1,
            'workers' => 1,
            'file_families' => ['FooService', 'BarService', 'BazService', 'QuxService', 'QuuxService'],
            'recent_index_lock_events' => 2,
        ]);

        $this->assertSame('high', $result['contention_risk']);
        $this->assertSame('git_index_lock_contention_observed', $result['bottleneck_reason']);
        $this->assertFalse($result['safe_to_add_another_muscle']);
        $this->assertGreaterThanOrEqual(1, $result['recommended_worker_count']);
    }

    public function test_lease_claimed_record_mismatch_with_active_leases_is_medium_contention(): void
    {
        $result = (new AtlasExternalBrainMuscleLeaseContentionPredictor)->predict([
            'active_leases' => 4,
            'claimed_records' => 2,
            'workers' => 1,
            'file_families' => [],
        ]);

        $this->assertSame('medium', $result['contention_risk']);
        $this->assertSame('lease_claimed_record_mismatch', $result['bottleneck_reason']);
        $this->assertTrue($result['facts']['lease_claimed_record_mismatch']);
    }

    public function test_no_file_family_data_with_clean_leases_reports_low_risk_with_explicit_reason(): void
    {
        $result = (new AtlasExternalBrainMuscleLeaseContentionPredictor)->predict([
            'active_leases' => 0,
            'claimed_records' => 0,
            'workers' => 0,
            'file_families' => [],
        ]);

        $this->assertSame('low', $result['contention_risk']);
        $this->assertSame('no_file_family_data', $result['bottleneck_reason']);
        $this->assertTrue($result['safe_to_add_another_muscle']);
    }
}

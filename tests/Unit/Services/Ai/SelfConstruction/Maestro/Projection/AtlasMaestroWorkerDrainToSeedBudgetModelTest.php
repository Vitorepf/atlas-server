<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkerDrainToSeedBudgetModel;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkerDrainToSeedBudgetModelTest extends TestCase
{
    private AtlasMaestroWorkerDrainToSeedBudgetModel $model;

    protected function setUp(): void
    {
        $this->model = new AtlasMaestroWorkerDrainToSeedBudgetModel;
    }

    public function test_dry_queue_requests_maximum_seeds(): void
    {
        $result = $this->model->derive([
            'claimable_depth' => 0,
            'drain_rate_per_hour' => 5.0,
            'worker_count' => 3,
        ]);

        $this->assertSame(AtlasMaestroWorkerDrainToSeedBudgetModel::MAX_SEEDS, $result['seed_budget']);
        $this->assertSame('dry_queue_max_seeds', $result['reason']);
    }

    public function test_sufficient_depth_requests_bounded_seeds(): void
    {
        $result = $this->model->derive([
            'claimable_depth' => 30,
            'drain_rate_per_hour' => 4.0,
            'worker_count' => 2,
        ]);

        $this->assertLessThanOrEqual(3, $result['seed_budget']);
        $this->assertSame('sufficient_depth_bounded', $result['reason']);
    }

    public function test_active_drain_increases_budget(): void
    {
        $result = $this->model->derive([
            'claimable_depth' => 5,
            'drain_rate_per_hour' => 8.0,
            'worker_count' => 3,
        ]);

        $this->assertGreaterThan(AtlasMaestroWorkerDrainToSeedBudgetModel::MIN_SEEDS, $result['seed_budget']);
        $this->assertSame('drain_based_budget', $result['reason']);
    }

    public function test_schema_present(): void
    {
        $result = $this->model->derive([]);
        $this->assertSame(AtlasMaestroWorkerDrainToSeedBudgetModel::SCHEMA, $result['schema']);
    }
}

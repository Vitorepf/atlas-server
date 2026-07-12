<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\AcosMax\AtlasResourceBudgetService;
use App\Services\Ai\Cognition\Watchdog\Checks\JointResourceBudgetWatchdogCheck;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasResourceBudgetTest extends TestCase
{
    #[Test]
    public function default_budget_covers_every_residents_declared_by_the_program(): void
    {
        $service = new AtlasResourceBudgetService();
        $report = $service->report();

        $names = array_map(static fn (array $row): string => (string) $row['name'], $report['components']);

        foreach ([
            'postgres_16',
            'semantic_rag_daemon',
            'pgvector_hnsw',
            'mcp_atlas_open_brain',
            'aobg_hooks',
            'laravel_workers',
        ] as $expected) {
            $this->assertContains($expected, $names, "component {$expected} must be budgeted");
        }
    }

    #[Test]
    public function paper_sum_leaves_declared_headroom_for_the_engine(): void
    {
        $service = new AtlasResourceBudgetService();
        $report = $service->report();

        $this->assertSame('paper_fits', $report['declared_paper_status']);
        $this->assertGreaterThanOrEqual(0, $report['paper_headroom_mb']);
    }

    #[Test]
    public function measured_overshoot_fires_named_reason(): void
    {
        $budget = [
            'schema_version' => 'atlas.resource_budget.v1',
            'host_ram_gib' => 4,
            'engine_floor_gib' => 1,
            'components' => [
                ['name' => 'runaway_worker', 'ram_cap_mb' => 512, 'disk_cap_mb' => 128, 'cpu_share' => 'shared'],
            ],
        ];

        $probe = static fn (string $name): array => match ($name) {
            'runaway_worker' => ['ram_mb' => 4096, 'disk_mb' => 0],
            default => ['ram_mb' => 0, 'disk_mb' => 0],
        };

        $service = new AtlasResourceBudgetService($budget, $probe);
        $check = new JointResourceBudgetWatchdogCheck($service);
        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertContains('component_over_ram_cap', $result['alert']['reasons']);
        $this->assertContains('measured_overshoot', $result['alert']['reasons']);
        $this->assertContains('runaway_worker', $result['evidence']['over_cap_components']);
    }

    #[Test]
    public function paper_overshoot_fires_when_declared_sum_breaks_host_ceiling(): void
    {
        $budget = [
            'schema_version' => 'atlas.resource_budget.v1',
            'host_ram_gib' => 2,
            'engine_floor_gib' => 1,
            'components' => [
                ['name' => 'oversized_a', 'ram_cap_mb' => 2048, 'disk_cap_mb' => 0, 'cpu_share' => 'shared'],
            ],
        ];

        $service = new AtlasResourceBudgetService($budget);
        $check = new JointResourceBudgetWatchdogCheck($service);
        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertContains('paper_overshoot', $result['alert']['reasons']);
    }

    #[Test]
    public function unmeasured_components_never_certify_only_paper(): void
    {
        $service = new AtlasResourceBudgetService();
        $report = $service->report();

        foreach ($report['components'] as $row) {
            $this->assertSame('unmeasured', $row['status']);
        }
    }
}

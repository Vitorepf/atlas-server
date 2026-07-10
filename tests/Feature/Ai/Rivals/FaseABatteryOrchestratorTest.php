<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use Tests\TestCase;

class FaseABatteryOrchestratorTest extends TestCase
{
    public function test_dry_run_bare_lists_ten_suites_with_case_packs(): void
    {
        $payload = (new FaseABatteryOrchestrator)->dryRun('bare');
        $this->assertSame('atlas.rivals2.fase_a_battery_dry_run.v1', $payload['schema_version']);
        $this->assertSame(10, $payload['suite_count']);
        $this->assertFalse($payload['execute_allowed_here']);
        $this->assertSame('verboo_kimi_k2_7', $payload['primary_model']);
        $this->assertCount(10, $payload['plans']);
        $this->assertSame((new SuiteRegistry)->externalSuiteIds(), array_column($payload['plans'], 'suite_id'));
        foreach ($payload['plans'] as $plan) {
            $this->assertGreaterThanOrEqual(3, $plan['case_count'], $plan['suite_id']);
            $this->assertSame(['verboo_kimi_k2_7@bare'], $plan['arms']);
        }
    }

    public function test_dry_run_uplift_uses_five_family_suites_and_dual_arms(): void
    {
        $payload = (new FaseABatteryOrchestrator)->dryRun('uplift');
        $this->assertSame(5, $payload['suite_count']);
        foreach ($payload['plans'] as $plan) {
            $this->assertSame(
                ['verboo_kimi_k2_7@bare', 'verboo_kimi_k2_7@atlas_dev'],
                $plan['arms'],
            );
        }
    }

    public function test_battery_cli_dry_run_action(): void
    {
        config()->set('atlas_rivals.enabled', false);
        $exit = $this->withoutMockingConsoleOutput()
            ->artisan('atlas:rivals', [
                'action' => 'battery',
                '--mode' => 'bare',
                '--json' => true,
            ]);
        $this->assertSame(0, $exit);
    }
}

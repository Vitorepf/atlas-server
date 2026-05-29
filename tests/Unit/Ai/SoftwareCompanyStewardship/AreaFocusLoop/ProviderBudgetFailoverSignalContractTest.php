<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderBudgetFailoverSignalContract;
use Tests\TestCase;

final class ProviderBudgetFailoverSignalContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ProviderBudgetFailoverSignalContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(ProviderBudgetFailoverSignalContract::class));
    }

    public function test_default_shape_exposes_provider_budget_failover_fields(): void
    {
        $shape = ProviderBudgetFailoverSignalContract::defaults()->toArray();

        $this->assertSame(ProviderBudgetFailoverSignalContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('provider_budget_exhausted', $shape['signal_id']);
        $this->assertSame(20, $shape['failover_threshold_pct']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
            $shape['gap_matrix_canonical'],
        );
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'run_id' => '',
            'provider_calls' => 0,
            'provider_calls_hard_ceiling' => ProviderBudgetFailoverSignalContract::DEFAULT_PROVIDER_CALLS_HARD_CEILING,
            'remaining_provider_budget_pct' => null,
        ], $shape['inputs']);
        $this->assertSame(100, $shape['outputs']['remaining_provider_budget_pct']);
        $this->assertFalse($shape['outputs']['triggers_provider_failover']);
        $this->assertNull($shape['outputs']['signal_id']);
    }

    public function test_from_array_triggers_failover_when_remaining_below_threshold(): void
    {
        $shape = ProviderBudgetFailoverSignalContract::fromArray([
            'run_id' => 'run-001',
            'provider_calls' => 200,
            'provider_calls_hard_ceiling' => 240,
        ])->toArray();

        $this->assertSame('run-001', $shape['inputs']['run_id']);
        $this->assertSame(200, $shape['inputs']['provider_calls']);
        $this->assertSame(16, $shape['outputs']['remaining_provider_budget_pct']);
        $this->assertTrue($shape['outputs']['triggers_provider_failover']);
        $this->assertSame('provider_budget_exhausted', $shape['outputs']['signal_id']);
    }

    public function test_from_array_does_not_trigger_failover_when_remaining_at_or_above_threshold(): void
    {
        $shape = ProviderBudgetFailoverSignalContract::fromArray([
            'provider_calls' => 192,
            'provider_calls_hard_ceiling' => 240,
        ])->toArray();

        $this->assertSame(20, $shape['outputs']['remaining_provider_budget_pct']);
        $this->assertFalse($shape['outputs']['triggers_provider_failover']);
        $this->assertNull($shape['outputs']['signal_id']);
    }

    public function test_from_array_accepts_explicit_remaining_provider_budget_pct(): void
    {
        $shape = ProviderBudgetFailoverSignalContract::fromArray([
            'provider_calls' => 10,
            'provider_calls_hard_ceiling' => 240,
            'remaining_provider_budget_pct' => 15,
        ])->toArray();

        $this->assertSame(15, $shape['inputs']['remaining_provider_budget_pct']);
        $this->assertSame(15, $shape['outputs']['remaining_provider_budget_pct']);
        $this->assertTrue($shape['outputs']['triggers_provider_failover']);
        $this->assertSame('provider_budget_exhausted', $shape['outputs']['signal_id']);
    }

    public function test_from_array_clamps_explicit_remaining_provider_budget_pct(): void
    {
        $shape = ProviderBudgetFailoverSignalContract::fromArray([
            'remaining_provider_budget_pct' => 150,
        ])->toArray();

        $this->assertSame(100, $shape['inputs']['remaining_provider_budget_pct']);
        $this->assertSame(100, $shape['outputs']['remaining_provider_budget_pct']);
        $this->assertFalse($shape['outputs']['triggers_provider_failover']);
    }
}

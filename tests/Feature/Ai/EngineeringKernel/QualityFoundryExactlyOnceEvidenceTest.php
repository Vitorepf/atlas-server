<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\HermeticSandboxPort;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class QualityFoundryExactlyOnceEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_05_17_230000_create_ai_real_engineering_execution_kernel_tables.php'))->up();
        (require database_path('migrations/2026_05_17_232000_create_ai_engineering_company_runtime_tables.php'))->up();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_mutative_kernel_fixture_invokes_provider_and_sandbox_once_then_blocks_without_release(): void
    {
        $scope = ['app/QualityFoundryProbe.php'];
        $provider = $this->createMock(ProviderPort::class);
        $provider->expects(self::once())->method('invoke')->willReturn([
            'status' => 'ok',
            'provider_invoked' => true,
            'patch_plan' => ['allowed_files' => $scope],
        ]);
        $sandbox = $this->createMock(HermeticSandboxPort::class);
        $sandbox->expects(self::once())->method('execute')->willThrowException(
            new \RuntimeException('quality_foundry_exactly_once_probe'),
        );
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->instance(HermeticSandboxPort::class, $sandbox);
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $outcome = $this->app->make(EliteExecutorKernel::class)->execute(
            (new EngineeringModeExecutionOrderFactory)->make([
                'run_id' => 'quality-foundry-exactly-once',
                'delivery_id' => 'quality-foundry-exactly-once',
                'mode' => 'dev',
                'risk_class' => 'R3',
                'complexity_band' => 'C3',
                'duration_regime' => 'interactive',
                'work_topology' => 'single',
                'product_intent_verdict_hash' => hash('sha256', 'qf-exactly-once-intent'),
                'spec_hash' => hash('sha256', 'qf-exactly-once-spec'),
                'world_model_snapshot_hash' => hash('sha256', 'qf-exactly-once-world'),
                'market_decision_hash' => hash('sha256', 'qf-exactly-once-market'),
                'workspace' => base_path(),
                'base_commit' => str_repeat('a', 40),
                'allowed_scope' => $scope,
                'forbidden_scope' => ['.env'],
                'authority_envelope' => [
                    'kind' => 'quality_foundry_exactly_once',
                    'authority_hash' => hash('sha256', 'qf-exactly-once-authority'),
                ],
                'operator_contract' => ['presence' => 'confirmed'],
                'provider_route' => ['provider' => 'fixture', 'model' => 'fixture'],
                'mutate' => true,
                'experiment_ref' => 'quality-foundry-exactly-once',
                'idempotency_key' => 'quality-foundry:exactly-once',
            ]),
        );

        self::assertSame('blocked', $outcome->status);
        self::assertFalse($outcome->claimEligible);
        self::assertSame('not_authorized', $outcome->releaseReceipt['status']);
        self::assertSame('not_applicable', $outcome->canaryRollbackReceipt['status']);
        self::assertSame('ok', $outcome->providerReceipt['status'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\FalseClaimInvariant;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use Tests\TestCase;

final class EliteExecutorKernelHonestyContractTest extends TestCase
{
    public function test_unified_honesty_contract_names_shared_invariant(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $contract = $kernel->unifiedHonestyContract();

        $this->assertSame('atlas.elite_kernel.unified_honesty.v1', $contract['schema_version']);
        $this->assertSame(OutcomeProofGate::class, $contract['outcome_proof']);
        $this->assertSame(SovereignHonestyFloor::class, $contract['sovereign_floor']);
        $this->assertSame(FalseClaimInvariant::class, $contract['shared_invariant']);
        $this->assertSame($kernel->outcomeProof(), $kernel->outcomeProof());
        $this->assertInstanceOf(SovereignHonestyFloor::class, $kernel->honestyFloor());
    }

    public function test_assert_honest_outcome_rejects_fake_green_execution(): void
    {
        $kernel = app(EliteExecutorKernel::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('elite_kernel_fake_green');

        $kernel->assertHonestOutcome([
            'status' => 'success',
            'execution' => [
                'claimed_status' => 'passed',
                'tests_run' => 0,
                'assertions' => 0,
                'commands' => [],
            ],
        ], 'dev');
    }
}

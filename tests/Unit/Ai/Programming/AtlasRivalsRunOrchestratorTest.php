<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\AtlasRivalsRunOrchestrator;
use App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService;
use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use PHPUnit\Framework\TestCase;

class AtlasRivalsRunOrchestratorTest extends TestCase
{
    public function test_unsupported_mode_blocked_response_carries_separation_invariant(): void
    {
        $orchestrator = new AtlasRivalsRunOrchestrator(
            $this->createMock(AtlasForgeNativeRivalsPreflightService::class),
            $this->createMock(AtlasForgeNativeRivalsDryRunService::class),
            $this->createMock(AtlasRivalsEvidencePackService::class),
            $this->createMock(AtlasRivalsEvidencePackVerifierService::class),
            $this->createMock(RivalsForgeReadinessFingerprintService::class),
            $this->createMock(RivalsForgeRunLogStreamService::class),
            $this->createMock(WorkspaceHygieneService::class),
        );

        $result = $orchestrator->run(['mode' => 'not_a_real_mode']);

        $this->assertSame(AtlasRivalsRunOrchestrator::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame('blocked', $result['verdict']);
        $this->assertSame('unsupported_mode', $result['details']['reason']);
        $this->assertSame('not_a_real_mode', $result['details']['mode']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['claim_ready']);
        $this->assertTrue($result['separated_from_external_rivals_certification']);
    }

    public function test_schema_contract_exposes_supported_modes(): void
    {
        $this->assertSame(
            [
                AtlasRivalsRunOrchestrator::MODE_DRY_RUN,
                AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
                AtlasRivalsRunOrchestrator::MODE_REAL_PROVIDER,
            ],
            AtlasRivalsRunOrchestrator::SUPPORTED_MODES,
        );
    }
}

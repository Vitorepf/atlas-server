<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopChaosCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderTimeoutRecoveryPathContract;
use Tests\TestCase;

final class ProviderTimeoutRecoveryPathContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ProviderTimeoutRecoveryPathContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(ProviderTimeoutRecoveryPathContract::class));
    }

    public function test_default_shape_declares_provider_timeout_recovery_path(): void
    {
        $shape = ProviderTimeoutRecoveryPathContract::defaults()->toArray();

        $this->assertSame(ProviderTimeoutRecoveryPathContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('provider_timeout_recovery_path', $shape['scenario_id']);
        $this->assertSame('provider_timeout', $shape['fault_id']);
        $this->assertSame(LoopChaosCertificationService::OUTCOME_BOUNDED_RETRY, $shape['mandated_chaos_outcome']);
        $this->assertSame('owner_runtime_provider_timeout', $shape['transient_blocker']);
        $this->assertSame(
            AreaFocusCandidateQuarantineService::SCHEMA,
            $shape['ap790_quarantine_schema'],
        );
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
            $shape['gap_matrix_canonical'],
        );
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'finding_key' => '',
            'cycle_index' => 0,
            'observed_outcome' => null,
            'blocker' => null,
            'same_finding_reselected' => null,
        ], $shape['inputs']);
        $this->assertFalse($shape['outputs']['observed_outcome_matches_mandate']);
        $this->assertFalse($shape['outputs']['triggers_transient_quarantine']);
        $this->assertFalse($shape['outputs']['same_stuck_selection']);
        $this->assertFalse($shape['outputs']['retries_next_cycle_not_same_selection']);
        $this->assertFalse($shape['outputs']['recovery_path_valid']);
    }

    public function test_from_array_marks_recovery_path_valid_for_transient_quarantine_and_next_cycle_retry(): void
    {
        $shape = ProviderTimeoutRecoveryPathContract::fromArray([
            'finding_key' => 'aaeos_loop_chaos_cert_provider_timeout_path',
            'cycle_index' => 2,
            'observed_outcome' => LoopChaosCertificationService::OUTCOME_BOUNDED_RETRY,
            'blocker' => 'owner_runtime_provider_timeout',
            'same_finding_reselected' => false,
        ])->toArray();

        $this->assertSame('aaeos_loop_chaos_cert_provider_timeout_path', $shape['inputs']['finding_key']);
        $this->assertSame(2, $shape['inputs']['cycle_index']);
        $this->assertTrue($shape['outputs']['observed_outcome_matches_mandate']);
        $this->assertTrue($shape['outputs']['triggers_transient_quarantine']);
        $this->assertFalse($shape['outputs']['same_stuck_selection']);
        $this->assertTrue($shape['outputs']['retries_next_cycle_not_same_selection']);
        $this->assertTrue($shape['outputs']['recovery_path_valid']);
    }

    public function test_from_array_rejects_same_finding_reselected_stuck_selection(): void
    {
        $shape = ProviderTimeoutRecoveryPathContract::fromArray([
            'finding_key' => 'aaeos_loop_chaos_cert_provider_timeout_path',
            'cycle_index' => 3,
            'observed_outcome' => LoopChaosCertificationService::OUTCOME_BOUNDED_RETRY,
            'blocker' => 'owner_runtime_provider_timeout',
            'same_finding_reselected' => true,
        ])->toArray();

        $this->assertTrue($shape['outputs']['same_stuck_selection']);
        $this->assertFalse($shape['outputs']['retries_next_cycle_not_same_selection']);
        $this->assertFalse($shape['outputs']['recovery_path_valid']);
    }

    public function test_from_array_rejects_non_transient_blocker(): void
    {
        $shape = ProviderTimeoutRecoveryPathContract::fromArray([
            'finding_key' => 'aaeos_loop_chaos_cert_provider_timeout_path',
            'observed_outcome' => LoopChaosCertificationService::OUTCOME_BOUNDED_RETRY,
            'blocker' => 'owner_runtime_no_patch_needed',
            'same_finding_reselected' => false,
        ])->toArray();

        $this->assertFalse($shape['outputs']['triggers_transient_quarantine']);
        $this->assertFalse($shape['outputs']['recovery_path_valid']);
    }

    public function test_from_array_rejects_observed_outcome_mismatch(): void
    {
        $shape = ProviderTimeoutRecoveryPathContract::fromArray([
            'finding_key' => 'aaeos_loop_chaos_cert_provider_timeout_path',
            'observed_outcome' => LoopChaosCertificationService::OUTCOME_PREFLIGHT_BLOCK,
            'blocker' => 'owner_runtime_provider_timeout',
            'same_finding_reselected' => false,
        ])->toArray();

        $this->assertFalse($shape['outputs']['observed_outcome_matches_mandate']);
        $this->assertFalse($shape['outputs']['recovery_path_valid']);
    }
}

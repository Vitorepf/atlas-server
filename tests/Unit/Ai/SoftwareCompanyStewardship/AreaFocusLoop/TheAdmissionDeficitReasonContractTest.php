<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheAdmissionDeficitReasonContract;
use Tests\TestCase;

final class TheAdmissionDeficitReasonContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheAdmissionDeficitReasonContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(TheAdmissionDeficitReasonContract::class));
    }

    public function test_default_shape_declares_admission_deficit_reason_contract(): void
    {
        $shape = TheAdmissionDeficitReasonContract::defaults()->toArray();

        $this->assertSame(TheAdmissionDeficitReasonContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('admission_deficit_reason', $shape['contract_id']);
        $this->assertSame(
            'docs/ap/AP-806-loop-autonomy-certification-contract.md',
            $shape['ap806_canonical'],
        );
        $this->assertSame(
            LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED,
            $shape['preflight_block_status'],
        );
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'selection_rejection_reasons' => [],
            'candidates_considered' => 0,
        ], $shape['inputs']);
        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_GENUINELY_EMPTY,
            $shape['outputs']['admission_deficit_reason'],
        );
        $this->assertSame([
            'review_locked' => 0,
            'authority_gated' => 0,
            'routine_test' => 0,
            'other' => 0,
        ], $shape['outputs']['rejection_bucket_counts']);
        $this->assertTrue($shape['outputs']['surfaces_operator_actionable_reason']);
    }

    public function test_from_array_classifies_all_review_locked(): void
    {
        $shape = TheAdmissionDeficitReasonContract::fromArray([
            'selection_rejection_reasons' => [
                'review_locked_existing_branch',
                'review_locked_existing_branch',
            ],
            'candidates_considered' => 2,
        ])->toArray();

        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_ALL_REVIEW_LOCKED,
            $shape['outputs']['admission_deficit_reason'],
        );
        $this->assertSame(2, $shape['outputs']['rejection_bucket_counts']['review_locked']);
        $this->assertTrue($shape['outputs']['surfaces_operator_actionable_reason']);
    }

    public function test_from_array_classifies_all_authority_gated(): void
    {
        $shape = TheAdmissionDeficitReasonContract::fromArray([
            'selection_rejection_reasons' => [
                'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
                'factory_max_rejects_forge_without_live_authority',
            ],
            'candidates_considered' => 2,
        ])->toArray();

        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_ALL_AUTHORITY_GATED,
            $shape['outputs']['admission_deficit_reason'],
        );
        $this->assertSame(2, $shape['outputs']['rejection_bucket_counts']['authority_gated']);
        $this->assertTrue($shape['outputs']['surfaces_operator_actionable_reason']);
    }

    public function test_from_array_classifies_all_routine_test(): void
    {
        $shape = TheAdmissionDeficitReasonContract::fromArray([
            'selection_rejection_reasons' => [
                'routine_missing_test_filler_in_high_power_mode',
                'missing_test',
            ],
            'candidates_considered' => 2,
        ])->toArray();

        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_ALL_ROUTINE_TEST,
            $shape['outputs']['admission_deficit_reason'],
        );
        $this->assertSame(2, $shape['outputs']['rejection_bucket_counts']['routine_test']);
        $this->assertTrue($shape['outputs']['surfaces_operator_actionable_reason']);
    }

    public function test_from_array_reports_mixed_profile_when_buckets_differ(): void
    {
        $shape = TheAdmissionDeficitReasonContract::fromArray([
            'selection_rejection_reasons' => [
                'review_locked_existing_branch',
                'factory_max_rejects_forge_without_live_authority',
            ],
            'candidates_considered' => 2,
        ])->toArray();

        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_MIXED,
            $shape['outputs']['admission_deficit_reason'],
        );
        $this->assertFalse($shape['outputs']['surfaces_operator_actionable_reason']);
    }
}

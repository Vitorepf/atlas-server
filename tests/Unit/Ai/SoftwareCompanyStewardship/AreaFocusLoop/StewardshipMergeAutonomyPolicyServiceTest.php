<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\APerClassChangedFileCeilingSignalContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipMergeAutonomyPolicyService;
use Tests\TestCase;

final class StewardshipMergeAutonomyPolicyServiceTest extends TestCase
{
    public function test_allows_small_docs_or_tests_without_operator_code_flag(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'docs_and_tests'],
            ['passed' => null],
            ['docs/a.md', 'tests/FooTest.php'],
            1,
            [],
            [],
        );

        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['eligible']);
        $this->assertSame('p3_low_risk_docs_tests', $policy['risk_class']);
        $this->assertSame('ff_only', $policy['merge_mode']);
        $this->assertSame([], $policy['reasons']);
    }

    public function test_allows_bugfix_only_with_operator_flag_and_green_validation(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'bugfix', 'code_or_other_file_count' => 1],
            ['passed' => true],
            ['app/Foo.php'],
            1,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible']);
        $this->assertTrue($policy['code_auto_merge_authorized']);
        $this->assertSame('p2_code_review_boundary', $policy['risk_class']);
        $this->assertSame('Fast-forward only. If accepted and later reverted, use a normal revert commit on the base branch; never reset, rebase, force-push or silently discard branch history.', $policy['rollback_plan']);
    }

    public function test_allows_focused_test_finding_with_minimal_code_fix_when_authorized_and_validated(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'test', 'code_or_other_file_count' => 1],
            ['passed' => true],
            ['app/Foo.php', 'tests/Unit/FooTest.php'],
            1,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible']);
        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['code_auto_merge_authorized']);
        $this->assertSame('p2_code_review_boundary', $policy['risk_class']);
        $this->assertTrue($policy['operator_controls']['allow_code_auto_merge_flag_required']);
        $this->assertTrue($policy['operator_controls']['validation_green_required_for_code']);
    }

    public function test_allows_factory_scoped_code_mixed_change_when_authorized_and_validated(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
            ],
            1,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible']);
        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['code_auto_merge_authorized']);
        $this->assertTrue($policy['factory_scoped_code_auto_merge_authorized']);
        $this->assertFalse($policy['operator_controls']['human_review_required_for_code_or_mixed']);
    }

    public function test_allows_factory_scoped_code_mixed_change_with_two_commits(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyServiceTest.php',
            ],
            2,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible'], 'branchOnly=2 must be eligible for factory-scoped path');
        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['factory_scoped_code_auto_merge_authorized']);
    }

    public function test_allows_factory_scoped_code_mixed_change_with_three_commits(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyServiceTest.php',
            ],
            3,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible'], 'branchOnly=3 must be eligible for factory-scoped path');
        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['factory_scoped_code_auto_merge_authorized']);
    }

    public function test_blocks_factory_scoped_code_mixed_change_with_four_or_more_commits(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyServiceTest.php',
            ],
            4,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertFalse($policy['eligible'], 'branchOnly=4 must NOT be eligible (ceiling is 3)');
        $this->assertContains('change_class_requires_operator_review', $policy['reasons']);
    }

    public function test_blocks_code_mixed_outside_factory_scope_even_when_authorized(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            ['app/Services/Ai/Programming/AtlasDev/Runtime/AtlasDevRuntimeService.php'],
            1,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertFalse($policy['eligible']);
        $this->assertFalse($policy['factory_scoped_code_auto_merge_authorized']);
        $this->assertContains('change_class_requires_operator_review', $policy['reasons']);
        $this->assertTrue($policy['operator_controls']['human_review_required_for_code_or_mixed']);
    }

    public function test_allows_bounded_self_construction_packet_on_integration_lane_when_authorized_and_validated(): void
    {
        $files = [
            'app/Services/Ai/AgenticEngineeringOs/QualityBarTelemetryContract.php',
            'tests/Unit/Ai/AgenticEngineeringOs/QualityBarTelemetryContractTest.php',
        ];

        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            $files,
            1,
            [],
            [
                'allow_code_auto_merge' => true,
                'merge_target' => 'integration_lane',
                'origin_type' => 'self_construction_admission_packet',
                'bounded_packet_auto_merge' => true,
                'bounded_packet_allowed_files' => $files,
            ],
        );

        $this->assertTrue($policy['eligible']);
        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['code_auto_merge_authorized']);
        $this->assertTrue($policy['bounded_packet_code_auto_merge_authorized']);
        $this->assertFalse($policy['factory_scoped_code_auto_merge_authorized']);
        $this->assertFalse($policy['operator_controls']['human_review_required_for_code_or_mixed']);
        $this->assertNotContains('change_class_requires_operator_review', $policy['reasons']);
    }

    public function test_bounded_packet_exception_never_allows_main_target_or_files_outside_packet_scope(): void
    {
        $files = [
            'app/Services/Ai/AgenticEngineeringOs/QualityBarTelemetryContract.php',
            'tests/Unit/Ai/AgenticEngineeringOs/QualityBarTelemetryContractTest.php',
        ];

        $mainPolicy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            $files,
            1,
            [],
            [
                'allow_code_auto_merge' => true,
                'merge_target' => 'main',
                'origin_type' => 'self_construction_admission_packet',
                'bounded_packet_auto_merge' => true,
                'bounded_packet_allowed_files' => $files,
            ],
        );

        $scopePolicy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'code_or_mixed', 'code_or_other_file_count' => 1],
            ['passed' => true],
            array_merge($files, ['app/Services/Ai/AgenticEngineeringOs/Unexpected.php']),
            1,
            [],
            [
                'allow_code_auto_merge' => true,
                'merge_target' => 'integration_lane',
                'origin_type' => 'self_construction_admission_packet',
                'bounded_packet_auto_merge' => true,
                'bounded_packet_allowed_files' => $files,
            ],
        );

        $this->assertFalse($mainPolicy['eligible']);
        $this->assertContains('change_class_requires_operator_review', $mainPolicy['reasons']);
        $this->assertFalse($scopePolicy['eligible']);
        $this->assertContains('change_class_requires_operator_review', $scopePolicy['reasons']);
    }

    public function test_blocks_code_without_operator_flag_or_validation(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'bugfix', 'code_or_other_file_count' => 1],
            ['passed' => null],
            ['app/Foo.php'],
            1,
            [],
            [],
        );

        $this->assertFalse($policy['eligible']);
        $this->assertContains('change_class_requires_operator_review', $policy['reasons']);
        $this->assertSame('operator_review_required', $policy['status']);
        $this->assertTrue($policy['operator_controls']['allow_code_auto_merge_flag_required']);
    }

    public function test_blocks_conflict_or_large_branch_even_when_otherwise_safe(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'documentation_only'],
            ['passed' => null],
            ['docs/a.md'],
            1,
            ['merge_conflict_detected'],
            [],
        );

        $this->assertFalse($policy['eligible']);
        $this->assertSame('p0_blocked', $policy['risk_class']);
        $this->assertContains('branch_blockers_present', $policy['reasons']);
        $this->assertContains('risk_class_blocks_auto_merge', $policy['reasons']);
    }

    public function test_empty_input_per_class_changed_file_ceiling_signal_returns_default_contract(): void
    {
        $signal = app(StewardshipMergeAutonomyPolicyService::class)->evaluatePerClassChangedFileCeilingSignal([]);

        $this->assertSame(
            APerClassChangedFileCeilingSignalContract::defaults()->toArray(),
            $signal,
        );
        $this->assertSame(APerClassChangedFileCeilingSignalContract::SCHEMA, $signal['schema_version']);
        $this->assertSame('per_class_changed_file_ceiling_exceeded', $signal['signal_id']);
        $this->assertSame(0, $signal['outputs']['changed_file_count']);
        $this->assertFalse($signal['outputs']['exceeds_per_class_changed_file_ceiling']);
    }

    public function test_docs_and_tests_changed_files_surface_per_class_ceiling_signal_through_entry_method(): void
    {
        $input = [
            'merge_class' => APerClassChangedFileCeilingSignalContract::MERGE_CLASS_DOCS_AND_TESTS,
            'changed_files' => [
                'docs/a.md',
                'docs/b.md',
                'docs/c.md',
                'docs/d.md',
                'docs/e.md',
                'docs/f.md',
            ],
            'max_auto_merge_files' => 5,
        ];

        $signal = app(StewardshipMergeAutonomyPolicyService::class)->evaluatePerClassChangedFileCeilingSignal($input);

        $this->assertSame(6, $signal['outputs']['changed_file_count']);
        $this->assertSame(5, $signal['outputs']['configured_per_class_changed_file_ceiling']);
        $this->assertTrue($signal['outputs']['exceeds_per_class_changed_file_ceiling']);
        $this->assertSame('per_class_changed_file_ceiling_exceeded', $signal['outputs']['signal_id']);
        $this->assertSame('changed_file_count_exceeds_policy', $signal['outputs']['policy_reason']);
    }
}

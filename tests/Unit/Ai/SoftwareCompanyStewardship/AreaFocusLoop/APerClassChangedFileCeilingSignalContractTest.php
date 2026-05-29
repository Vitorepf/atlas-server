<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\APerClassChangedFileCeilingSignalContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipMergeAutonomyPolicyService;
use Tests\TestCase;

final class APerClassChangedFileCeilingSignalContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/APerClassChangedFileCeilingSignalContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(APerClassChangedFileCeilingSignalContract::class));
    }

    public function test_default_shape_declares_per_class_changed_file_ceiling_signal(): void
    {
        $shape = APerClassChangedFileCeilingSignalContract::defaults()->toArray();

        $this->assertSame(APerClassChangedFileCeilingSignalContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('per_class_changed_file_ceiling_exceeded', $shape['signal_id']);
        $this->assertSame(StewardshipMergeAutonomyPolicyService::DECISION_SCHEMA, $shape['decision_schema']);
        $this->assertSame(
            'docs/ap/AP-774-stewardship-merge-autonomy-policy-contract.md',
            $shape['ap774_canonical'],
        );
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
            $shape['quality_bar_matrix_canonical'],
        );
        $this->assertTrue($shape['informational_signal_only']);
        $this->assertSame(5, $shape['default_max_auto_merge_files']);
        $this->assertSame(2, $shape['factory_scoped_changed_file_ceiling']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'merge_class' => APerClassChangedFileCeilingSignalContract::MERGE_CLASS_DOCS_AND_TESTS,
            'changed_files' => [],
            'max_auto_merge_files' => APerClassChangedFileCeilingSignalContract::DEFAULT_MAX_AUTO_MERGE_FILES,
            'bounded_packet_allowed_files' => [],
        ], $shape['inputs']);
        $this->assertSame(5, $shape['outputs']['configured_per_class_changed_file_ceiling']);
        $this->assertSame(0, $shape['outputs']['changed_file_count']);
        $this->assertFalse($shape['outputs']['exceeds_per_class_changed_file_ceiling']);
        $this->assertFalse($shape['outputs']['surfaces_scope_creep_before_merge']);
        $this->assertNull($shape['outputs']['signal_id']);
        $this->assertNull($shape['outputs']['policy_reason']);
    }

    public function test_from_array_does_not_exceed_for_docs_and_tests_within_global_ceiling(): void
    {
        $shape = APerClassChangedFileCeilingSignalContract::fromArray([
            'merge_class' => 'docs_and_tests',
            'changed_files' => ['docs/a.md', 'tests/FooTest.php'],
            'max_auto_merge_files' => 5,
        ])->toArray();

        $this->assertSame(2, $shape['outputs']['changed_file_count']);
        $this->assertSame(5, $shape['outputs']['configured_per_class_changed_file_ceiling']);
        $this->assertFalse($shape['outputs']['exceeds_per_class_changed_file_ceiling']);
        $this->assertNull($shape['outputs']['signal_id']);
    }

    public function test_from_array_surfaces_exceeds_when_changed_files_above_max_auto_merge_files(): void
    {
        $shape = APerClassChangedFileCeilingSignalContract::fromArray([
            'merge_class' => 'bugfix',
            'changed_files' => [
                'app/A.php',
                'app/B.php',
                'app/C.php',
                'app/D.php',
                'app/E.php',
                'app/F.php',
            ],
            'max_auto_merge_files' => 5,
        ])->toArray();

        $this->assertSame(6, $shape['outputs']['changed_file_count']);
        $this->assertSame(5, $shape['outputs']['configured_per_class_changed_file_ceiling']);
        $this->assertTrue($shape['outputs']['exceeds_per_class_changed_file_ceiling']);
        $this->assertTrue($shape['outputs']['surfaces_scope_creep_before_merge']);
        $this->assertSame('per_class_changed_file_ceiling_exceeded', $shape['outputs']['signal_id']);
        $this->assertSame('changed_file_count_exceeds_policy', $shape['outputs']['policy_reason']);
    }

    public function test_from_array_bounded_packet_ceiling_matches_allowed_files_count(): void
    {
        $allowed = [
            'app/Services/Ai/AgenticEngineeringOs/QualityBarTelemetryContract.php',
            'tests/Unit/Ai/AgenticEngineeringOs/QualityBarTelemetryContractTest.php',
        ];

        $shape = APerClassChangedFileCeilingSignalContract::fromArray([
            'merge_class' => 'bounded_packet_code_or_mixed',
            'changed_files' => array_merge($allowed, ['app/Services/Ai/AgenticEngineeringOs/Unexpected.php']),
            'bounded_packet_allowed_files' => $allowed,
            'max_auto_merge_files' => 5,
        ])->toArray();

        $this->assertSame(2, $shape['outputs']['configured_per_class_changed_file_ceiling']);
        $this->assertSame(3, $shape['outputs']['changed_file_count']);
        $this->assertTrue($shape['outputs']['exceeds_per_class_changed_file_ceiling']);
        $this->assertSame('changed_file_count_exceeds_policy', $shape['outputs']['policy_reason']);
    }

    public function test_from_array_factory_scoped_ceiling_limits_to_two_app_test_files(): void
    {
        $shape = APerClassChangedFileCeilingSignalContract::fromArray([
            'merge_class' => 'factory_scoped_code_or_mixed',
            'changed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyServiceTest.php',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Extra.php',
            ],
        ])->toArray();

        $this->assertSame(2, $shape['outputs']['configured_per_class_changed_file_ceiling']);
        $this->assertSame(3, $shape['outputs']['changed_file_count']);
        $this->assertTrue($shape['outputs']['exceeds_per_class_changed_file_ceiling']);
        $this->assertSame('per_class_changed_file_ceiling_exceeded', $shape['outputs']['signal_id']);
    }
}

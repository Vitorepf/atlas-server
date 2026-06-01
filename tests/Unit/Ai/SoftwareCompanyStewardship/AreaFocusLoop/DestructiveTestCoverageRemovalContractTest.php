<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DestructiveTestCoverageRemovalContract;
use Tests\TestCase;

final class DestructiveTestCoverageRemovalContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DestructiveTestCoverageRemovalContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(DestructiveTestCoverageRemovalContract::class));
    }

    public function test_defaults_preserves_coverage_with_no_trigger_reasons(): void
    {
        $shape = DestructiveTestCoverageRemovalContract::defaults()->toArray();

        $this->assertSame(DestructiveTestCoverageRemovalContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('destructive_test_coverage_removal', $shape['contract_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
            $shape['quality_bar_matrix_canonical'],
        );
        $this->assertSame(2, $shape['trivial_product_change_floor']);
        $this->assertSame([
            'test_insertions' => 0,
            'test_deletions' => 0,
            'product_insertions' => 0,
            'product_deletions' => 0,
            'test_files_deleted_count' => 0,
        ], $shape['inputs']);
        $this->assertSame('coverage_preserved', $shape['outputs']['verdict']);
        $this->assertFalse($shape['outputs']['coverage_removed']);
        $this->assertSame([], $shape['outputs']['trigger_reasons']);
    }

    public function test_rule_a_whole_test_file_deleted_with_no_product_change(): void
    {
        $shape = DestructiveTestCoverageRemovalContract::fromArray([
            'test_files_deleted_count' => 1,
            'product_insertions' => 0,
            'product_deletions' => 0,
        ])->toArray();

        $this->assertSame('coverage_removed', $shape['outputs']['verdict']);
        $this->assertTrue($shape['outputs']['coverage_removed']);
        $this->assertTrue($shape['outputs']['whole_test_file_deleted_with_no_product_change']);
        $this->assertSame(
            ['whole_test_file_deleted_with_no_product_change'],
            $shape['outputs']['trigger_reasons'],
        );
    }

    public function test_rule_b_net_test_lines_dropped_without_product_growth(): void
    {
        $shape = DestructiveTestCoverageRemovalContract::fromArray([
            'test_deletions' => 12,
            'test_insertions' => 3,
            'product_insertions' => 0,
            'product_deletions' => 0,
        ])->toArray();

        $this->assertSame('coverage_removed', $shape['outputs']['verdict']);
        $this->assertTrue($shape['outputs']['net_test_lines_dropped_without_product_growth']);
        $this->assertContains(
            'net_test_lines_dropped_without_product_growth',
            $shape['outputs']['trigger_reasons'],
        );
    }

    public function test_rule_c_test_cut_masked_by_trivial_product_edit(): void
    {
        $shape = DestructiveTestCoverageRemovalContract::fromArray([
            'test_deletions' => 5,
            'test_insertions' => 0,
            'product_insertions' => 1,
            'product_deletions' => 1,
        ])->toArray();

        $this->assertSame('coverage_removed', $shape['outputs']['verdict']);
        $this->assertTrue($shape['outputs']['test_cut_masked_by_trivial_product_edit']);
        $this->assertContains(
            'test_cut_masked_by_trivial_product_edit',
            $shape['outputs']['trigger_reasons'],
        );
    }

    public function test_healthy_diff_with_product_growth_preserves_coverage(): void
    {
        $shape = DestructiveTestCoverageRemovalContract::fromArray([
            'test_insertions' => 20,
            'test_deletions' => 5,
            'product_insertions' => 40,
            'product_deletions' => 2,
        ])->toArray();

        $this->assertSame('coverage_preserved', $shape['outputs']['verdict']);
        $this->assertFalse($shape['outputs']['coverage_removed']);
        $this->assertSame([], $shape['outputs']['trigger_reasons']);
    }

    public function test_from_array_clamps_negative_inputs_to_zero(): void
    {
        $shape = DestructiveTestCoverageRemovalContract::fromArray([
            'test_insertions' => -4,
            'test_deletions' => -1,
            'product_insertions' => -2,
            'product_deletions' => -3,
            'test_files_deleted_count' => -5,
        ])->toArray();

        $this->assertSame([
            'test_insertions' => 0,
            'test_deletions' => 0,
            'product_insertions' => 0,
            'product_deletions' => 0,
            'test_files_deleted_count' => 0,
        ], $shape['inputs']);
        $this->assertSame('coverage_preserved', $shape['outputs']['verdict']);
    }
}

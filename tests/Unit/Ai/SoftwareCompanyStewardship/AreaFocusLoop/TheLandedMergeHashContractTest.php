<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheLandedMergeHashContract;
use Tests\TestCase;

final class TheLandedMergeHashContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheLandedMergeHashContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(TheLandedMergeHashContract::class));
    }

    public function test_default_shape_declares_landed_merge_hash_cross_link(): void
    {
        $shape = TheLandedMergeHashContract::defaults()->toArray();

        $this->assertSame(TheLandedMergeHashContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('landed_merge_hash_cross_link', $shape['contract_id']);
        $this->assertSame('aaeos_evidence_pack_merge_hash_cross_link', $shape['finding_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
            $shape['gap_matrix_canonical'],
        );
        $this->assertSame(AreaFocusEvidencePackService::PACK_SCHEMA, $shape['evidence_pack_schema']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'cycle_id' => '',
            'merge_hash' => '',
            'branch_ref' => '',
        ], $shape['inputs']);
        $this->assertSame('', $shape['outputs']['landed_merge_hash']);
        $this->assertSame('', $shape['outputs']['branch_ref']);
        $this->assertFalse($shape['outputs']['merge_hash_cross_link_ready']);
        $this->assertFalse($shape['outputs']['provably_tied_to_git_commit']);
    }

    public function test_from_array_surfaces_landed_merge_hash_when_merge_and_branch_present(): void
    {
        $shape = TheLandedMergeHashContract::fromArray([
            'cycle_id' => 'afc_0123456789abcdef',
            'merge_hash' => '939b8268f527c21f81961a014066b7816e4613d7',
            'branch_ref' => 'refs/heads/main',
        ])->toArray();

        $this->assertSame('afc_0123456789abcdef', $shape['inputs']['cycle_id']);
        $this->assertSame(
            '939b8268f527c21f81961a014066b7816e4613d7',
            $shape['outputs']['landed_merge_hash'],
        );
        $this->assertSame('refs/heads/main', $shape['outputs']['branch_ref']);
        $this->assertTrue($shape['outputs']['merge_hash_cross_link_ready']);
        $this->assertTrue($shape['outputs']['provably_tied_to_git_commit']);
    }

    public function test_from_array_resolves_merge_hash_from_loop_receipt(): void
    {
        $shape = TheLandedMergeHashContract::fromArray([
            'loop_receipt' => [
                'merge_hash' => 'abc1234',
            ],
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os',
        ])->toArray();

        $this->assertSame('abc1234', $shape['outputs']['landed_merge_hash']);
        $this->assertSame('atlas/area-focus/agentic_engineering_os', $shape['outputs']['branch_ref']);
        $this->assertTrue($shape['outputs']['merge_hash_cross_link_ready']);
    }

    public function test_from_array_reports_cross_link_not_ready_when_merge_hash_missing(): void
    {
        $shape = TheLandedMergeHashContract::fromArray([
            'branch_ref' => 'refs/heads/main',
        ])->toArray();

        $this->assertSame('', $shape['outputs']['landed_merge_hash']);
        $this->assertFalse($shape['outputs']['merge_hash_cross_link_ready']);
        $this->assertFalse($shape['outputs']['provably_tied_to_git_commit']);
    }
}

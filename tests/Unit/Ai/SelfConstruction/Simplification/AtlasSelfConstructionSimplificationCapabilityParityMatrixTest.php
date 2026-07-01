<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationCapabilityParityMatrix;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationCapabilityParityMatrixTest extends TestCase
{
    private function fullOrgan(): array
    {
        return [
            'behavior_claims' => ['claim_a'],
            'input_contract' => ['field_x'],
            'output_fields' => ['result'],
            'failure_modes' => ['timeout'],
            'proof_refs' => ['test_run_1'],
            'public_command_contracts' => ['ConsumerA'],
            'runtime_contracts' => ['runtime_hook_1'],
        ];
    }

    public function test_full_parity_allows_replacement(): void
    {
        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $this->fullOrgan(),
        ]);

        self::assertTrue($result['replacement_allowed']);
        foreach ($result['rows'] as $row) {
            self::assertSame(AtlasSelfConstructionSimplificationCapabilityParityMatrix::STATUS_FULL_PARITY, $row['status']);
            self::assertSame([], $row['missing_fields']);
        }
    }

    public function test_partial_parity_on_one_dimension_refuses_replacement(): void
    {
        $newOrgan = $this->fullOrgan();
        $newOrgan['output_fields'] = [];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $newOrgan,
        ]);

        self::assertFalse($result['replacement_allowed']);

        $outputRow = collect($result['rows'])->firstWhere('capability', 'output_fields');
        self::assertSame(AtlasSelfConstructionSimplificationCapabilityParityMatrix::STATUS_MISSING, $outputRow['status']);
        self::assertSame(['result'], $outputRow['missing_fields']);
        self::assertNotNull($outputRow['recommended_fix']);
    }

    public function test_missing_proof_refs_refuses_replacement(): void
    {
        $newOrgan = $this->fullOrgan();
        $newOrgan['proof_refs'] = [];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $newOrgan,
        ]);

        self::assertFalse($result['replacement_allowed']);
        $proofRow = collect($result['rows'])->firstWhere('capability', 'proof_refs');
        self::assertSame(['test_run_1'], $proofRow['missing_fields']);
    }

    public function test_no_requirement_on_empty_old_dimension_does_not_block(): void
    {
        $oldOrgan = $this->fullOrgan();
        $oldOrgan['failure_modes'] = [];
        $newOrgan = $this->fullOrgan();
        $newOrgan['failure_modes'] = [];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $oldOrgan,
            'new_organ' => $newOrgan,
        ]);

        self::assertTrue($result['replacement_allowed']);
        $row = collect($result['rows'])->firstWhere('capability', 'failure_modes');
        self::assertSame(AtlasSelfConstructionSimplificationCapabilityParityMatrix::STATUS_NO_REQUIREMENT, $row['status']);
    }

    public function test_partial_overlap_is_partial_parity_status(): void
    {
        $oldOrgan = $this->fullOrgan();
        $oldOrgan['behavior_claims'] = ['claim_a', 'claim_b'];
        $newOrgan = $this->fullOrgan();
        $newOrgan['behavior_claims'] = ['claim_a'];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $oldOrgan,
            'new_organ' => $newOrgan,
        ]);

        $row = collect($result['rows'])->firstWhere('capability', 'behavior_claims');
        self::assertSame(AtlasSelfConstructionSimplificationCapabilityParityMatrix::STATUS_PARTIAL_PARITY, $row['status']);
        self::assertSame(['claim_b'], $row['missing_fields']);
    }

    public function test_replacement_missing_one_old_capability_reports_missing_capability_and_parity_false(): void
    {
        $newOrgan = $this->fullOrgan();
        $newOrgan['output_fields'] = [];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $newOrgan,
        ]);

        self::assertFalse($result['parity']);
        self::assertContains('output_fields', $result['missing_capabilities']);

        $outputRow = collect($result['rows'])->firstWhere('capability', 'output_fields');
        self::assertTrue($outputRow['missing_capability']);
    }

    public function test_full_parity_with_fewer_helpers_reports_parity_true_and_simplification_gain(): void
    {
        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $this->fullOrgan(),
            'old_helper_count' => 5,
            'new_helper_count' => 2,
        ]);

        self::assertTrue($result['parity']);
        self::assertSame([], $result['missing_capabilities']);
        self::assertSame(3, $result['simplification_gain']);
    }

    public function test_rows_are_sorted_deterministically_by_capability_id(): void
    {
        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $this->fullOrgan(),
        ]);

        $capabilities = array_column($result['rows'], 'capability');
        $sorted = $capabilities;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $capabilities);
    }

    public function test_missing_public_command_contracts_refuses_replacement_despite_full_parity_elsewhere(): void
    {
        $newOrgan = $this->fullOrgan();
        $newOrgan['public_command_contracts'] = [];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $newOrgan,
        ]);

        self::assertFalse($result['replacement_allowed']);
        $row = collect($result['rows'])->firstWhere('capability', 'public_command_contracts');
        self::assertSame(AtlasSelfConstructionSimplificationCapabilityParityMatrix::STATUS_MISSING, $row['status']);
    }

    public function test_missing_runtime_contracts_reports_distinct_missing_fields_and_recommended_fix(): void
    {
        $newOrgan = $this->fullOrgan();
        $newOrgan['runtime_contracts'] = [];

        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $newOrgan,
        ]);

        $row = collect($result['rows'])->firstWhere('capability', 'runtime_contracts');
        self::assertSame(['runtime_hook_1'], $row['missing_fields']);
        self::assertNotNull($row['recommended_fix']);
        self::assertFalse($result['replacement_allowed']);
    }

    public function test_full_parity_across_all_dimensions_computes_simplification_gain(): void
    {
        $result = (new AtlasSelfConstructionSimplificationCapabilityParityMatrix)->compare([
            'old_organ' => $this->fullOrgan(),
            'new_organ' => $this->fullOrgan(),
            'old_helper_count' => 4,
            'new_helper_count' => 1,
        ]);

        self::assertTrue($result['replacement_allowed']);
        self::assertSame(3, $result['simplification_gain']);
    }
}

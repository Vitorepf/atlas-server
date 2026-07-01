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
}

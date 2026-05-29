<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\ArchitectAgentSpecPackGateContract;
use Tests\TestCase;

final class ArchitectAgentSpecPackGateContractTest extends TestCase
{
    private const CONTRACT_PATH = __DIR__.'/../../../../app/Services/Ai/AgenticEngineeringOs/ArchitectAgentSpecPackGateContract.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ArchitectAgentSpecPackGateContract::class, false)) {
            require_once self::CONTRACT_PATH;
        }
    }

    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $this->assertFileExists(self::CONTRACT_PATH);
        $this->assertTrue(class_exists(ArchitectAgentSpecPackGateContract::class));
    }

    public function test_default_shape_declares_r4_architect_spec_pack_gate(): void
    {
        $shape = ArchitectAgentSpecPackGateContract::defaults()->toArray();

        $this->assertSame(ArchitectAgentSpecPackGateContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('architecture', $shape['department_id']);
        $this->assertSame('R4', $shape['min_autonomous_risk_scope']);
        $this->assertSame('R5', $shape['operator_signature_required_from']);
        $this->assertSame('atlas.spec_pack.v1', $shape['spec_pack_schema']);
        $this->assertSame([
            'acceptance_criteria',
            'rollback_plan',
            'breaking_change_matrix',
        ], $shape['required_spec_pack_artifacts']);
        $this->assertSame([
            'adr_published',
            'boundary_validated',
            'spec_acceptance_criteria_complete',
            'breaking_change_documented',
            'rollback_per_slice',
        ], $shape['gates']);
        $this->assertSame([
            'spec_pack_hash',
            'architect_decision_receipt',
        ], $shape['evidence_required']);
        $this->assertSame([
            'risk_scope' => 'R4',
            'spec_pack_hash' => '',
            'acceptance_criteria_present' => false,
            'rollback_plan_present' => false,
            'breaking_change_matrix_present' => false,
            'operator_signature_present' => false,
        ], $shape['inputs']);
    }

    public function test_from_array_preserves_spec_pack_gate_inputs(): void
    {
        $shape = ArchitectAgentSpecPackGateContract::fromArray([
            'risk_scope' => 'R4',
            'spec_pack_hash' => 'abc123',
            'acceptance_criteria_present' => true,
            'rollback_plan_present' => true,
            'breaking_change_matrix_present' => true,
            'operator_signature_present' => false,
        ])->toArray();

        $this->assertSame('abc123', $shape['inputs']['spec_pack_hash']);
        $this->assertTrue($shape['inputs']['acceptance_criteria_present']);
        $this->assertTrue($shape['inputs']['rollback_plan_present']);
        $this->assertTrue($shape['inputs']['breaking_change_matrix_present']);
    }
}

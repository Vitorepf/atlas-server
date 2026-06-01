<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheWeakFindingContractGradeContract;
use Tests\TestCase;

final class TheWeakFindingContractGradeContractTest extends TestCase
{
    public function test_concrete_finding_grades_actionable_with_no_deficits(): void
    {
        $shape = TheWeakFindingContractGradeContract::fromArray([
            'evidence_refs' => ['app/Services/Ai/Foo.php:42'],
            'affected_paths' => ['app/Services/Ai/Foo.php'],
            'recommended_action' => 'Wire the missing X.',
            'detail' => 'Concrete gap.',
        ])->toArray();

        $this->assertSame('actionable_contract', $shape['outputs']['contract_grade']);
        $this->assertFalse($shape['outputs']['blocks_before_spend']);
        $this->assertSame([], $shape['outputs']['deficit_reasons']);
        $this->assertSame(1, $shape['outputs']['concrete_evidence_ref_count']);
    }

    public function test_fully_deficient_finding_grades_weak_with_all_deficits_sorted(): void
    {
        $shape = TheWeakFindingContractGradeContract::fromArray([
            'evidence_refs' => ['replay_expected:true'],
            'affected_paths' => [],
            'recommended_action' => 'Operator review required.',
            'detail' => '',
        ])->toArray();

        $this->assertSame('weak_contract', $shape['outputs']['contract_grade']);
        $this->assertTrue($shape['outputs']['blocks_before_spend']);
        $this->assertSame([
            'default_recommended_action',
            'empty_affected_paths',
            'empty_detail',
            'no_concrete_evidence_refs',
        ], $shape['outputs']['deficit_reasons']);
        $this->assertSame(0, $shape['outputs']['concrete_evidence_ref_count']);
    }

    public function test_synthetic_stub_alone_yields_only_no_concrete_evidence_deficit(): void
    {
        $shape = TheWeakFindingContractGradeContract::fromArray([
            'evidence_refs' => ['desktop_expected:true'],
            'affected_paths' => ['docs/x.md'],
            'recommended_action' => 'Add a desktop surface reference.',
            'detail' => 'Has detail.',
        ])->toArray();

        $this->assertSame(['no_concrete_evidence_refs'], $shape['outputs']['deficit_reasons']);
        $this->assertTrue($shape['outputs']['blocks_before_spend']);
    }

    public function test_default_recommended_action_alone_yields_weak_contract(): void
    {
        $shape = TheWeakFindingContractGradeContract::fromArray([
            'evidence_refs' => ['app/x.php:9'],
            'affected_paths' => ['app/x.php'],
            'recommended_action' => 'Operator review required.',
            'detail' => 'ok',
        ])->toArray();

        $this->assertSame(['default_recommended_action'], $shape['outputs']['deficit_reasons']);
        $this->assertSame('weak_contract', $shape['outputs']['contract_grade']);
    }

    public function test_defaults_declare_weak_finding_contract_grade_envelope(): void
    {
        $defaults = TheWeakFindingContractGradeContract::defaults()->toArray();

        $this->assertSame(TheWeakFindingContractGradeContract::SCHEMA, $defaults['schema_version']);
        $this->assertSame('weak_finding_contract_grade', $defaults['contract_id']);
        $this->assertSame(
            LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED,
            $defaults['preflight_block_status'],
        );
        $this->assertSame('weak_contract', $defaults['outputs']['contract_grade']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheWeakFindingContractGradeContract;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: the firewall report
 * (atlas.software_company_stewardship.loop_preflight_firewall.v1) now carries
 * the advisory `weak_finding_contract_grade`. Gate B only blocks the EMPTY
 * source/evidence case; the grade observes the sub-threshold one (synthetic
 * stubs / bare tokens) WITHOUT adding a blocker or changing the status.
 */
final class LoopPreflightCycleFirewallWeakFindingGradeWireTest extends TestCase
{
    private function service(): LoopPreflightCycleFirewallService
    {
        return app(LoopPreflightCycleFirewallService::class);
    }

    public function test_synthetic_stub_evidence_is_graded_weak_without_a_new_blocker(): void
    {
        $report = $this->service()->evaluate([
            'candidate' => [
                'finding_id' => 'F-wire-weak',
                'evidence_refs' => ['replay_expected:true'],
                'affected_paths' => [],
                'recommended_action' => 'Operator review required.',
                'detail' => '',
            ],
        ]);

        $this->assertSame(
            LoopPreflightCycleFirewallService::REPORT_SCHEMA,
            $report['schema_version'],
        );

        $grade = $report['weak_finding_contract_grade'];
        $this->assertIsArray($grade);
        $this->assertSame(TheWeakFindingContractGradeContract::GRADE_WEAK, $grade['outputs']['contract_grade']);
        $this->assertTrue($grade['outputs']['blocks_before_spend']);
        $this->assertSame(0, $grade['outputs']['concrete_evidence_ref_count']);
        $this->assertContains(
            TheWeakFindingContractGradeContract::DEFICIT_NO_CONCRETE_EVIDENCE_REFS,
            $grade['outputs']['deficit_reasons'],
        );
        // Observe-only: Gate B's empty-evidence blocker must NOT fire — the
        // synthetic stub still satisfies today's presence check, proving the
        // grade fills the sub-threshold gap without changing the verdict.
        $this->assertNotContains('missing_source_doc_evidence_or_canonical_reason', $report['blockers']);
    }

    public function test_concrete_contract_is_graded_actionable(): void
    {
        $report = $this->service()->evaluate([
            'candidate' => [
                'finding_id' => 'F-wire-actionable',
                'evidence_refs' => ['app/Services/Ai/Foo.php:12'],
                'affected_paths' => ['app/Services/Ai/Foo.php'],
                'recommended_action' => 'Wire the scorer into the gate envelope.',
                'detail' => 'The scorer exists but no runtime consumer records it.',
            ],
        ]);

        $grade = $report['weak_finding_contract_grade'];
        $this->assertIsArray($grade);
        $this->assertSame(TheWeakFindingContractGradeContract::GRADE_ACTIONABLE, $grade['outputs']['contract_grade']);
        $this->assertFalse($grade['outputs']['blocks_before_spend']);
        $this->assertSame(1, $grade['outputs']['concrete_evidence_ref_count']);
        $this->assertSame([], $grade['outputs']['deficit_reasons']);
    }
}

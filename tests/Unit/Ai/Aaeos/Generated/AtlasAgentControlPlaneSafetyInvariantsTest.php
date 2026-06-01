<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneSafetyInvariantsService;
use Tests\TestCase;

/**
 * Pins the hard-law stop-condition contract from the Safety Invariants v1 doc:
 * exactly 16 invariants; missing evidence = violated; any violation downgrades
 * posture and blocks promotion (no fast path); I-06 self-programming is
 * non-negotiable; a relax/temporary-waiver flag never rescues a violation.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
 */
class AtlasAgentControlPlaneSafetyInvariantsTest extends TestCase
{
    private function service(): AtlasAgentControlPlaneSafetyInvariantsService
    {
        return new AtlasAgentControlPlaneSafetyInvariantsService;
    }

    public function test_doc_defines_exactly_sixteen_invariants_I01_through_I16(): void
    {
        $invariants = $this->service()->invariants();

        $this->assertCount(16, $invariants);
        $this->assertSame(16, AtlasAgentControlPlaneSafetyInvariantsService::INVARIANT_COUNT);

        $ids = array_column($invariants, 'id');
        $expected = [];
        for ($n = 1; $n <= 16; $n++) {
            $expected[] = sprintf('I-%02d', $n);
        }
        $this->assertSame($expected, $ids);
    }

    public function test_fully_evidenced_slice_is_green_ships_and_promotion_not_blocked(): void
    {
        $service = $this->service();
        $green = $service->fullySatisfiedSlice('slice-green');

        $result = $service->evaluateSlice($green);

        $this->assertSame('green', $result['posture']);
        $this->assertFalse($result['posture_downgraded']);
        $this->assertFalse($result['promotion_blocked']);
        $this->assertTrue($result['ships']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertFalse($result['runtime_authorized']); // never authorizes runtime
    }

    public function test_missing_evidence_is_treated_as_violated_and_downgrades_posture_and_blocks_promotion(): void
    {
        $service = $this->service();
        $green = $service->fullySatisfiedSlice('slice-missing-killswitch');

        // Drop exactly one required evidence key: the kill switch (I-13).
        unset($green['kill_switch']);

        $result = $service->evaluateSlice($green);

        $this->assertContains('I-13', $result['violated_ids']);
        $this->assertSame(1, $result['violation_count']);
        // Doc: any violation downgrades posture AND blocks promotion.
        $this->assertSame('downgraded', $result['posture']);
        $this->assertTrue($result['posture_downgraded']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertFalse($result['ships']);
        $this->assertTrue($result['repair_slice_required']);
    }

    public function test_empty_string_evidence_does_not_count_as_satisfied(): void
    {
        $service = $this->service();
        $green = $service->fullySatisfiedSlice('slice-empty-evidence');

        // "Looks fine" is not evidence: blank/zero strings must not pass.
        $green['budget_gate'] = '';     // I-02
        $green['claim_lease'] = '0';    // I-16

        $result = $service->evaluateSlice($green);

        $this->assertContains('I-02', $result['violated_ids']);
        $this->assertContains('I-16', $result['violated_ids']);
        $this->assertFalse($result['ships']);
    }

    public function test_self_programming_write_surface_violates_I06_even_with_evidence(): void
    {
        $service = $this->service();
        $green = $service->fullySatisfiedSlice('slice-self-programming');

        // Evidence key for I-06 is present, but a write surface over Atlas code
        // is declared. Doc: self-programming is explicitly disabled,
        // non-negotiable -> hard violation regardless of evidence.
        $green['writes_atlas_code'] = true;

        $result = $service->evaluateSlice($green);

        $this->assertContains('I-06', $result['violated_ids']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertFalse($result['ships']);
    }

    public function test_relax_flag_is_rejected_and_never_rescues_a_violation(): void
    {
        $service = $this->service();
        $green = $service->fullySatisfiedSlice('slice-relax-attempt');
        unset($green['rollback_path']); // I-14 violated

        // Doc: "An invariant cannot be 'temporarily relaxed'."
        $green['relax_invariant'] = true;

        $result = $service->evaluateSlice($green);

        $this->assertTrue($result['relax_attempt_rejected']);
        $this->assertContains('I-14', $result['violated_ids']);
        // The relax attempt must NOT flip the slice back to shippable.
        $this->assertFalse($result['ships']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertSame('downgraded', $result['posture']);
    }
}

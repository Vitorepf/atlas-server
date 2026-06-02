<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL7CompletionCertificationService;
use PHPUnit\Framework\TestCase;

final class AaeosL7CompletionCertificationServiceTest extends TestCase
{
    private AaeosL7CompletionCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new AaeosL7CompletionCertificationService();
    }

    /**
     * Every S83-S99 gate green, each with its own evidence ref.
     *
     * @return array<string,mixed>
     */
    private function allGatesPassing(): array
    {
        return [
            'loop_executes_real' => ['passed' => true, 'evidence_ref' => 'evidence://l7/loop_executes_real/s83'],
            'cohort_evidence_real' => ['passed' => true, 'evidence_ref' => 'evidence://l7/cohort_evidence_real/s84'],
            'utilization_quality_pass' => ['passed' => true, 'evidence_ref' => 'evidence://l7/utilization_quality_pass/s85'],
            'departments_l4' => ['passed' => true, 'evidence_ref' => 'evidence://l7/departments_l4/s87'],
            'http_path_mission_control' => ['passed' => true, 'evidence_ref' => 'evidence://l7/http_path_mission_control/s88'],
            'self_construction_proven' => ['passed' => true, 'evidence_ref' => 'evidence://l7/self_construction_proven/s92'],
            'trust_ledger_stable' => ['passed' => true, 'evidence_ref' => 'evidence://l7/trust_ledger_stable/s93'],
            'no_invariant_breach' => ['passed' => true, 'evidence_ref' => 'evidence://l7/no_invariant_breach/s94'],
        ];
    }

    /**
     * A valid S96-shaped promotion receipt.
     *
     * @return array<string,mixed>
     */
    private function validPromotionReceipt(): array
    {
        return [
            'applied' => true,
            'receipt_id' => 'promo-l6-l7-0001',
            'from_level' => 'L6',
            'to_level' => 'L7',
        ];
    }

    public function testCertifyReturnsChecklistPhasesZeroToEightCurrentLevelCertifiedBlockersAndEvidenceRefs(): void
    {
        $result = $this->service->certify([
            'gates' => $this->allGatesPassing(),
            'promotion_receipt' => $this->validPromotionReceipt(),
        ]);

        // Required keys named by the Acceptance row.
        $this->assertArrayHasKey('checklist', $result);
        $this->assertArrayHasKey('current_level', $result);
        $this->assertArrayHasKey('certified', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('evidence_refs', $result);

        $this->assertSame('atlas.aaeos.l7_completion_certification.v1', $result['schema_version']);

        // The checklist materialises exactly nine ordered phases, 0..8.
        $this->assertCount(9, $result['checklist']);
        $this->assertSame(9, $result['phase_total']);
        $phases = array_map(static fn (array $row): int => $row['phase'], $result['checklist']);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8], $phases);

        // certified bool is a real bool computed from the gates + receipt.
        $this->assertIsBool($result['certified']);
        $this->assertTrue($result['certified']);
    }

    public function testCertifiedTrueOnlyWhenAllGatesPassAndPromotionReceiptExists(): void
    {
        $result = $this->service->certify([
            'gates' => $this->allGatesPassing(),
            'promotion_receipt' => $this->validPromotionReceipt(),
        ]);

        $this->assertTrue($result['certified']);
        $this->assertSame('certified_l7', $result['status']);
        $this->assertSame('L7', $result['current_level']);
        $this->assertTrue($result['promotion_receipt_present']);
        $this->assertSame([], $result['blockers']);

        // All nine phases passed, so nine evidence refs flow through in order,
        // the ninth being the promotion receipt's own id.
        $this->assertSame(9, $result['phases_passed_count']);
        $this->assertCount(9, $result['evidence_refs']);
        $this->assertSame(
            [
                'evidence://l7/loop_executes_real/s83',
                'evidence://l7/cohort_evidence_real/s84',
                'evidence://l7/utilization_quality_pass/s85',
                'evidence://l7/departments_l4/s87',
                'evidence://l7/http_path_mission_control/s88',
                'evidence://l7/self_construction_proven/s92',
                'evidence://l7/trust_ledger_stable/s93',
                'evidence://l7/no_invariant_breach/s94',
                'promo-l6-l7-0001',
            ],
            $result['evidence_refs'],
        );
    }

    public function testWithoutPromotionReceiptReturnsNeedsSignatureOrPromotion(): void
    {
        // Every S83-S99 gate green, but no promotion receipt at all.
        $result = $this->service->certify([
            'gates' => $this->allGatesPassing(),
        ]);

        $this->assertSame('needs_signature_or_promotion', $result['status']);
        $this->assertFalse($result['certified']);
        // Code is ready but the runtime has NOT promoted: level stays at L6.
        $this->assertSame('L6', $result['current_level']);
        $this->assertFalse($result['promotion_receipt_present']);

        // The receipt gap is the single, honest blocker — never hidden.
        $this->assertSame(['promotion_receipt_missing'], $result['blockers']);
        $this->assertSame(8, $result['phases_passed_count']);
    }

    public function testUnappliedPromotionReceiptStillNeedsSignatureOrPromotion(): void
    {
        // A receipt that exists but was not applied does not fake promotion.
        $result = $this->service->certify([
            'gates' => $this->allGatesPassing(),
            'promotion_receipt' => ['applied' => false, 'receipt_id' => 'promo-pending-7'],
        ]);

        $this->assertSame('needs_signature_or_promotion', $result['status']);
        $this->assertFalse($result['certified']);
        $this->assertFalse($result['promotion_receipt_present']);
        $this->assertSame(['promotion_receipt_missing'], $result['blockers']);
    }

    public function testSnapshotWithOneBlockerReturnsBlockedNotL7(): void
    {
        // One S83-S99 gate fails (trust ledger), receipt present.
        $gates = $this->allGatesPassing();
        $gates['trust_ledger_stable'] = ['passed' => false, 'evidence_ref' => 'evidence://l7/trust_ledger_stable/0949'];

        $result = $this->service->certify([
            'gates' => $gates,
            'promotion_receipt' => $this->validPromotionReceipt(),
        ]);

        $this->assertSame('blocked_not_l7', $result['status']);
        $this->assertFalse($result['certified']);
        $this->assertSame('L6', $result['current_level']);

        // The blocker is surfaced (not hidden); exactly one gate gap.
        $this->assertSame(['trust_ledger_below_threshold'], $result['blockers']);
        $this->assertSame(8, $result['phases_passed_count']);

        // An unmet gate never leaks its evidence ref into the proof list.
        $this->assertNotContains('evidence://l7/trust_ledger_stable/0949', $result['evidence_refs']);
    }

    public function testGateGapBeatsMissingReceiptInStatusPrecedence(): void
    {
        // Both a gate gap AND a missing receipt: a real gate gap dominates, so
        // the verdict is blocked_not_l7 (not needs_signature_or_promotion), and
        // BOTH blockers are surfaced in order — no blocker is hidden.
        $gates = $this->allGatesPassing();
        $gates['departments_l4'] = false;

        $result = $this->service->certify([
            'gates' => $gates,
        ]);

        $this->assertSame('blocked_not_l7', $result['status']);
        $this->assertFalse($result['certified']);
        $this->assertSame('L6', $result['current_level']);
        $this->assertSame(
            ['departments_below_l4', 'promotion_receipt_missing'],
            $result['blockers'],
        );
        $this->assertSame(7, $result['phases_passed_count']);
    }

    public function testEmptySnapshotBlocksWithAllGateBlockersInCanonicalOrder(): void
    {
        // No gates, no receipt: every phase fails. This proves the rules
        // generalise rather than keying off a single canned input.
        $result = $this->service->certify([]);

        $this->assertSame('blocked_not_l7', $result['status']);
        $this->assertFalse($result['certified']);
        $this->assertSame('L6', $result['current_level']);
        $this->assertSame(0, $result['phases_passed_count']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertSame(
            [
                'loop_not_real_tier_scan_only',
                'cohort_evidence_incomplete',
                'utilization_quality_below_threshold',
                'departments_below_l4',
                'http_path_or_mission_control_missing',
                'self_construction_proposals_incomplete',
                'trust_ledger_below_threshold',
                'invariant_breach_present',
                'promotion_receipt_missing',
            ],
            $result['blockers'],
        );
    }

    public function testBareBooleanGateAndAlternatePassKeysAreHonoured(): void
    {
        // Gates expressed as a bare bool and via `met`/`pass` aliases all certify,
        // and a met gate without an explicit ref derives a deterministic ref.
        $result = $this->service->certify([
            'gates' => [
                'loop_executes_real' => true,
                'cohort_evidence_real' => ['met' => true],
                'utilization_quality_pass' => ['pass' => true],
                'departments_l4' => true,
                'http_path_mission_control' => true,
                'self_construction_proven' => true,
                'trust_ledger_stable' => true,
                'no_invariant_breach' => true,
            ],
            'promotion_receipt' => ['present' => true, 'id' => 'promo-alt-1'],
        ]);

        $this->assertTrue($result['certified']);
        $this->assertSame('certified_l7', $result['status']);
        // Bare-true gate derives the prefixed met ref.
        $this->assertContains('evidence://l7/loop_executes_real/met', $result['evidence_refs']);
        // `present`+`id` receipt counts and contributes its id as evidence.
        $this->assertContains('promo-alt-1', $result['evidence_refs']);
    }

    public function testDoesNotHideBlockersAndNeverFakesPromotionWhenGateValueUnknown(): void
    {
        // Unknown/garbage gate evidence must fail-closed, not silently pass.
        $gates = $this->allGatesPassing();
        $gates['no_invariant_breach'] = ['note' => 'pending audit']; // no pass flag
        $gates['http_path_mission_control'] = 'maybe';               // non-bool, non-array

        $result = $this->service->certify([
            'gates' => $gates,
            'promotion_receipt' => $this->validPromotionReceipt(),
        ]);

        $this->assertFalse($result['certified']);
        $this->assertSame('blocked_not_l7', $result['status']);
        $this->assertContains('invariant_breach_present', $result['blockers']);
        $this->assertContains('http_path_or_mission_control_missing', $result['blockers']);
        // Blockers preserve canonical phase order (http=phase4 before invariant=phase7).
        $this->assertSame(
            ['http_path_or_mission_control_missing', 'invariant_breach_present'],
            $result['blockers'],
        );
    }

    public function testBlockersAndEvidenceRefsHonourListStringContract(): void
    {
        $gates = $this->allGatesPassing();
        unset($gates['self_construction_proven']);

        $result = $this->service->certify([
            'gates' => $gates,
            'promotion_receipt' => $this->validPromotionReceipt(),
        ]);

        $this->assertIsList($result['blockers']);
        $this->assertIsList($result['evidence_refs']);
        $this->assertIsList($result['checklist']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        foreach ($result['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
        }
        $this->assertIsInt($result['phases_passed_count']);
        $this->assertIsBool($result['promotion_receipt_present']);
    }

    public function testCertificationIsDeterministicAndDistinctSnapshotsDiffer(): void
    {
        $inputs = [
            'gates' => $this->allGatesPassing(),
            'promotion_receipt' => $this->validPromotionReceipt(),
        ];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);
        $this->assertSame($first, $second);

        // A different snapshot must produce a different verdict (no canned output).
        $blockedInputs = $inputs;
        unset($blockedInputs['promotion_receipt']);
        $blocked = $this->service->certify($blockedInputs);

        $this->assertNotSame($first['status'], $blocked['status']);
        $this->assertNotSame($first['certified'], $blocked['certified']);
        $this->assertNotSame($first['current_level'], $blocked['current_level']);
    }
}

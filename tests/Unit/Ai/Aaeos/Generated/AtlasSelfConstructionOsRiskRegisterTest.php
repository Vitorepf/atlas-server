<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsRiskRegisterService;
use Tests\TestCase;

/**
 * Pins the load-bearing rules from the Self-Construction OS Risk Register v1 doc:
 * the 15 frozen risks, the runtime-mitigation gate ("Regras para IA": green gate
 * + replay diff + signed receipt), the decisions signal invariant, the
 * cross-cutting canonical runtime_safety alarm, and the slice-promotion block on
 * open risks. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
 */
class AtlasSelfConstructionOsRiskRegisterTest extends TestCase
{
    private function service(): AtlasSelfConstructionOsRiskRegisterService
    {
        return new AtlasSelfConstructionOsRiskRegisterService;
    }

    /** Full, valid runtime-mitigation evidence bundle that promotes a risk. */
    private function fullEvidence(): array
    {
        return [
            'promotion_gate_status' => 'green',
            'replay_diff_status' => 'improved',
            'signed_receipt' => 'receipt-2026-001',
        ];
    }

    public function test_default_snapshot_freezes_fifteen_risks_with_documented_open_set(): void
    {
        $snapshot = $this->service()->snapshot();

        // Doc declares exactly 15 risks.
        $this->assertSame(15, $snapshot['risk_count']);

        // Every risk declares an operator-observable signal (decisions invariant).
        $this->assertTrue($snapshot['all_risks_have_signal']);

        // The doc's status legend is the closed enum.
        $this->assertSame(
            ['open', 'mitigated_by_design', 'mitigated_by_runtime', 'accepted', 'monitoring'],
            $snapshot['status_legend']
        );

        // The open risks are exactly the ones the doc flags as open (R-004,
        // R-008..R-014). R-014 is "open at the runtime level" per the doc.
        $this->assertSame(
            ['SC-OS-R-004', 'SC-OS-R-008', 'SC-OS-R-009', 'SC-OS-R-010', 'SC-OS-R-011', 'SC-OS-R-012', 'SC-OS-R-013', 'SC-OS-R-014'],
            $snapshot['open_risks']
        );
        $this->assertSame(8, $snapshot['open_count']);

        // Severity breakdown matches the doc (6 critical, 6 high, 3 medium).
        $this->assertSame(6, $snapshot['severity_breakdown']['critical']);
        $this->assertSame(6, $snapshot['severity_breakdown']['high']);
        $this->assertSame(3, $snapshot['severity_breakdown']['medium']);

        // Nothing is runtime-promotable without evidence.
        $this->assertSame([], $snapshot['runtime_promotable_open_risks']);
    }

    public function test_runtime_mitigation_requires_gate_replay_and_receipt_together(): void
    {
        $svc = $this->service();

        // Missing receipt -> refused, status holds at open.
        $noReceipt = $svc->evaluateRuntimeMitigation('SC-OS-R-004', [
            'promotion_gate_status' => 'green',
            'replay_diff_status' => 'improved',
        ]);
        $this->assertFalse($noReceipt['promoted']);
        $this->assertSame('open', $noReceipt['resulting_status']);
        $this->assertContains('signed_receipt', $noReceipt['missing_evidence']);
        $this->assertSame('missing_required_runtime_mitigation_evidence', $noReceipt['reason']);

        // Full, valid bundle -> promoted to mitigated_by_runtime.
        $full = $svc->evaluateRuntimeMitigation('SC-OS-R-004', $this->fullEvidence());
        $this->assertTrue($full['promoted']);
        $this->assertSame('mitigated_by_runtime', $full['resulting_status']);
        $this->assertSame([], $full['missing_evidence']);
        $this->assertSame('promoted_to_mitigated_by_runtime_gate_replay_and_receipt_all_present', $full['reason']);
    }

    public function test_runtime_mitigation_rejects_regressed_replay_and_non_green_gate(): void
    {
        $svc = $this->service();

        // A "regressed" replay diff is never acceptable (SC-OS-R-001 signal).
        $regressed = $svc->evaluateRuntimeMitigation('SC-OS-R-011', [
            'promotion_gate_status' => 'green',
            'replay_diff_status' => 'regressed',
            'signed_receipt' => 'receipt-x',
        ]);
        $this->assertFalse($regressed['promoted']);
        $this->assertContains('replay_diff_status', $regressed['invalid_evidence']);
        $this->assertSame('evidence_present_but_invalid_status_value', $regressed['reason']);

        // A non-green gate is rejected even with a good replay diff + receipt.
        $redGate = $svc->evaluateRuntimeMitigation('SC-OS-R-011', [
            'promotion_gate_status' => 'failed',
            'replay_diff_status' => 'passed',
            'signed_receipt' => 'receipt-y',
        ]);
        $this->assertFalse($redGate['promoted']);
        $this->assertContains('promotion_gate_status', $redGate['invalid_evidence']);
    }

    public function test_design_mitigated_risk_holds_its_status_when_evidence_absent(): void
    {
        // SC-OS-R-003 is mitigated_by_design; with no runtime evidence it does NOT
        // become mitigated_by_runtime — it holds its declared design status.
        $card = $this->service()->evaluateRisk('SC-OS-R-003');
        $this->assertSame('critical', $card['severity']);
        $this->assertSame('mitigated_by_design', $card['status']);
        $this->assertFalse($card['is_open']);
        $this->assertTrue($card['status_valid']);
        $this->assertTrue($card['has_signal']);
        $this->assertSame('execution_allowed', $card['runtime_flag']);
    }

    public function test_canonical_alarm_fires_when_runtime_flag_true_without_signed_release(): void
    {
        $svc = $this->service();

        // A runtime_safety flag true with NO signed release fires the alarm.
        $firing = $svc->evaluateCanonicalAlarm([
            'execution_allowed' => false,
            'dispatch_allowed' => true,
            'self_programming_allowed' => false,
        ], []);
        $this->assertTrue($firing['alarm_firing']);
        $this->assertFalse($firing['runtime_safety_all_false']);
        $this->assertSame(['dispatch_allowed'], $firing['firing_flags']);

        // Same flag true WITH a signed release does not fire (authorized).
        $authorized = $svc->evaluateCanonicalAlarm([
            'dispatch_allowed' => true,
        ], ['signed_real_invoker_release_001']);
        $this->assertFalse($authorized['alarm_firing']);
        $this->assertSame([], $authorized['firing_flags']);

        // All-false runtime_safety is the quiet, healthy state.
        $quiet = $svc->evaluateCanonicalAlarm([
            'execution_allowed' => false,
            'dispatch_allowed' => false,
        ], []);
        $this->assertFalse($quiet['alarm_firing']);
        $this->assertTrue($quiet['runtime_safety_all_false']);
    }

    public function test_open_risk_blocks_slice_promotion_until_runtime_mitigated(): void
    {
        $svc = $this->service();

        // A slice touching an open risk (R-008) and a design-mitigated risk
        // (R-003): the open one blocks, the design one clears.
        $blocked = $svc->evaluateSlicePromotion(['SC-OS-R-003', 'SC-OS-R-008']);
        $this->assertTrue($blocked['promotion_blocked']);
        $this->assertSame(['SC-OS-R-008'], $blocked['blocking_risks']);
        $this->assertSame(['SC-OS-R-003'], $blocked['cleared_risks']);
        $this->assertSame('open_risk_without_mitigation_runtime_blocks_slice_promotion', $blocked['reason']);

        // Supply full runtime-mitigation evidence for R-008 -> slice may promote.
        $cleared = $svc->evaluateSlicePromotion(
            ['SC-OS-R-003', 'SC-OS-R-008'],
            ['SC-OS-R-008' => $this->fullEvidence()]
        );
        $this->assertFalse($cleared['promotion_blocked']);
        $this->assertSame([], $cleared['blocking_risks']);
        $this->assertSame(['SC-OS-R-003', 'SC-OS-R-008'], $cleared['cleared_risks']);
    }

    public function test_id_normalization_and_unknown_risk_handling(): void
    {
        $svc = $this->service();

        // "4", "sc-os-r-4", "SCOSR004" all resolve to SC-OS-R-004.
        $this->assertSame('SC-OS-R-004', $svc->evaluateRisk('4')['id']);
        $this->assertSame('SC-OS-R-004', $svc->evaluateRisk('sc-os-r-4')['id']);
        $this->assertSame('SC-OS-R-004', $svc->evaluateRisk('SCOSR004')['id']);

        // Unknown risk is unrecognized and treated as open (blocks promotion).
        $unknown = $svc->evaluateRisk('SC-OS-R-999');
        $this->assertFalse($unknown['recognized']);
        $this->assertTrue($unknown['is_open']);
        $this->assertFalse($unknown['has_signal']);

        // And it can never be runtime-promoted.
        $mitigation = $svc->evaluateRuntimeMitigation('SC-OS-R-999', $this->fullEvidence());
        $this->assertFalse($mitigation['promoted']);
        $this->assertSame('unknown_risk_not_in_register', $mitigation['reason']);
    }
}

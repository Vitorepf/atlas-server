<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autopoiesis;

use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisOutcomeInterpreter;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionAutopoiesisOutcomeInterpreter: verification_passed + real leverage proof
 * + non-empty evidence ⇒ promote_candidate; verification_passed + proxy_only_signal ⇒ retry; missing
 * evidence_ref ⇒ reject; regression_detected ⇒ quarantine; retry_count above threshold ⇒ quarantine;
 * no learning is promoted without explicit evidence.
 */
final class AtlasSelfConstructionAutopoiesisOutcomeInterpreterTest extends TestCase
{
    public function test_promote_candidate_when_real_leverage_proof_and_evidence_present(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'impact_receipt_ref' => 'receipt-1',
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_PROMOTE, $r['verdict']);
    }

    public function test_retry_when_impact_receipt_ref_missing_despite_verification_and_leverage(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_RETRY, $r['verdict']);
        $this->assertContains('retry:impact_receipt_ref_missing', $r['reasons']);
    }

    public function test_promote_requires_both_evidence_ref_and_impact_receipt_ref(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => '',
            'real_leverage_proof' => true,
            'impact_receipt_ref' => 'receipt-1',
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_REJECT, $r['verdict']);
    }

    public function test_retry_when_proxy_only_signal_even_with_verification(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'proxy_only_signal' => true,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_RETRY, $r['verdict']);
        $this->assertContains('retry:proxy_only_signal', $r['reasons']);
    }

    public function test_retry_when_residual_risk_present(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'residual_risk_count' => 2,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_RETRY, $r['verdict']);
        $this->assertContains('retry:residual_risk:2', $r['reasons']);
    }

    public function test_retry_when_real_leverage_proof_missing(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => false,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_RETRY, $r['verdict']);
        $this->assertContains('retry:real_leverage_proof_missing', $r['reasons']);
    }

    public function test_reject_when_verification_not_passed(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => false,
            'evidence_ref' => 'evh',
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_REJECT, $r['verdict']);
        $this->assertContains('reject:verification_not_passed', $r['reasons']);
    }

    public function test_reject_when_evidence_ref_missing_no_learning_without_evidence(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'real_leverage_proof' => true,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_REJECT, $r['verdict']);
        $this->assertContains('reject:evidence_ref_missing', $r['reasons']);
    }

    public function test_quarantine_when_regression_detected(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh',
            'real_leverage_proof' => true,
            'regression_detected' => true,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_QUARANTINE, $r['verdict']);
    }

    public function test_quarantine_when_retry_count_exceeds_threshold(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh',
            'real_leverage_proof' => true,
            'retry_count' => 5,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_QUARANTINE, $r['verdict']);
    }

    // ── stale evidence: never promoted ──────────────────────────────────────

    public function test_retry_when_stale_evidence_detected_alone(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'impact_receipt_ref' => 'receipt-1',
            'real_leverage_proof' => true,
            'stale_evidence_detected' => true,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_RETRY, $r['verdict']);
        $this->assertContains('retry:stale_evidence_detected', $r['reasons']);
    }

    public function test_stale_evidence_folds_into_quarantine_when_quarantine_already_triggered(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'regression_detected' => true,
            'stale_evidence_detected' => true,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_QUARANTINE, $r['verdict']);
        $this->assertContains('quarantine:stale_evidence_detected', $r['reasons']);
        $this->assertContains('quarantine:regression_detected', $r['reasons']);
    }

    public function test_stale_evidence_alone_never_promotes(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'impact_receipt_ref' => 'receipt-1',
            'real_leverage_proof' => true,
            'stale_evidence_detected' => true,
        ]);
        $this->assertNotSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_PROMOTE, $r['verdict']);
    }

    // ── repeated residual risk quarantines even when verification_passed=true ──

    public function test_quarantine_when_residual_risk_count_at_or_above_retry_threshold(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'impact_receipt_ref' => 'receipt-1',
            'real_leverage_proof' => true,
            'residual_risk_count' => AtlasSelfConstructionAutopoiesisOutcomeInterpreter::QUARANTINE_RETRY_THRESHOLD,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_QUARANTINE, $r['verdict']);
        $this->assertContains(
            'quarantine:residual_risk_threshold_exceeded:'.AtlasSelfConstructionAutopoiesisOutcomeInterpreter::QUARANTINE_RETRY_THRESHOLD,
            $r['reasons'],
        );
    }

    public function test_residual_risk_below_threshold_still_only_retries(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'residual_risk_count' => AtlasSelfConstructionAutopoiesisOutcomeInterpreter::QUARANTINE_RETRY_THRESHOLD - 1,
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_RETRY, $r['verdict']);
    }

    // ── determinism: reasons sorted when multiple apply ─────────────────────

    public function test_multiple_quarantine_reasons_are_sorted_deterministically(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'regression_detected' => true,
            'retry_count' => 5,
            'residual_risk_count' => AtlasSelfConstructionAutopoiesisOutcomeInterpreter::QUARANTINE_RETRY_THRESHOLD,
            'stale_evidence_detected' => true,
        ]);
        $sorted = $r['reasons'];
        $expected = $r['reasons'];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $sorted);
        $this->assertGreaterThanOrEqual(3, count($r['reasons']));
    }

    public function test_multiple_retry_reasons_are_sorted_deterministically(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisOutcomeInterpreter)->interpret([
            'verification_passed' => true,
            'evidence_ref' => 'evh-1',
            'real_leverage_proof' => true,
            'proxy_only_signal' => true,
            'residual_risk_count' => 1,
            'stale_evidence_detected' => true,
        ]);
        $sorted = $r['reasons'];
        $expected = $r['reasons'];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $sorted);
        $this->assertContains('retry:proxy_only_signal', $r['reasons']);
        $this->assertContains('retry:residual_risk:1', $r['reasons']);
        $this->assertContains('retry:stale_evidence_detected', $r['reasons']);
    }
}

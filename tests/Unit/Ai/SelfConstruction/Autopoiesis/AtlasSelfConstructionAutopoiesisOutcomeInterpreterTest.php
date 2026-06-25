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
        ]);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_PROMOTE, $r['verdict']);
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
}

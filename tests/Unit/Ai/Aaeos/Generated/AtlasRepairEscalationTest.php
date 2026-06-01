<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRepairEscalationService;
use Tests\TestCase;

/**
 * Pins the documented repair-escalation decision rules.
 *
 * @see docs/engineering-knowledge-base/system-graph/repair-escalation.md
 */
class AtlasRepairEscalationTest extends TestCase
{
    private function service(): AtlasRepairEscalationService
    {
        return new AtlasRepairEscalationService();
    }

    /** Gate failure with evidence and remaining budget => controlled repair. */
    public function test_gate_failure_with_evidence_admits_controlled_repair(): void
    {
        $d = $this->service()->decide([
            'failure_domain' => 'gate.failed',
            'severity' => 'high',
            'evidence' => ['harness:run-1'],
            'attempt' => 1,
            'policy' => ['max_attempts' => 3],
        ]);

        $this->assertSame(AtlasRepairEscalationService::ACTION_REPAIR, $d['action']);
        $this->assertContains('controlled_repair_admitted', $d['reasons']);
        $this->assertSame(2, $d['remaining_attempts']);
        $this->assertTrue($this->service()->mayAttemptRepair([
            'failure_domain' => 'gate.failed',
            'severity' => 'high',
            'evidence' => ['harness:run-1'],
        ]));
    }

    /** "block heavy repair without evidence": no evidence => block, never repair. */
    public function test_missing_evidence_blocks_repair(): void
    {
        $d = $this->service()->decide([
            'failure_domain' => 'runtime.failed',
            'severity' => 'high',
            'evidence' => [],
            'attempt' => 1,
        ]);

        $this->assertSame(AtlasRepairEscalationService::ACTION_BLOCK, $d['action']);
        $this->assertContains('no_evidence_blocks_repair', $d['reasons']);
        $this->assertTrue($d['review_required']);
    }

    /**
     * Repair contract: policy/privacy/security/compliance/unknown go to human
     * review and are never auto-repaired, even with evidence and full budget.
     */
    public function test_security_finding_always_escalates_to_human_review(): void
    {
        foreach (['security.finding', 'privacy.violation', 'compliance.violation', 'policy.denied', 'unknown'] as $domain) {
            $d = $this->service()->decide([
                'failure_domain' => $domain,
                'severity' => 'warning',
                'evidence' => ['ledger:e1'],
                'attempt' => 1,
                'policy' => ['max_attempts' => 5],
            ]);

            $this->assertSame(
                AtlasRepairEscalationService::ACTION_ESCALATE,
                $d['action'],
                "domain {$domain} must escalate to human review",
            );
            $this->assertContains("domain_requires_human_review:{$domain}", $d['reasons']);
        }
    }

    /** Loop cap: at/over max attempts the loop must escalate, not repair again. */
    public function test_exhausted_attempt_budget_escalates_instead_of_looping(): void
    {
        $d = $this->service()->decide([
            'failure_domain' => 'runtime.failed',
            'severity' => 'high',
            'evidence' => ['harness:run-3'],
            'attempt' => 3,
            'policy' => ['max_attempts' => 3],
        ]);

        $this->assertSame(AtlasRepairEscalationService::ACTION_ESCALATE, $d['action']);
        $this->assertContains('attempts_exhausted:3/3', $d['reasons']);
        $this->assertSame(0, $d['remaining_attempts']);
    }

    /** Invariant: a critical failure can never resolve silently. */
    public function test_critical_failure_never_passes_silently(): void
    {
        // Even a normally-repairable gate failure, when critical, is forced to
        // flag for review so it cannot pass unseen.
        $d = $this->service()->decide([
            'failure_domain' => 'gate.failed',
            'severity' => 'critical',
            'evidence' => ['harness:run-1'],
            'attempt' => 1,
            'policy' => ['max_attempts' => 3],
        ]);

        $this->assertTrue($d['critical']);
        $this->assertTrue($d['review_required'], 'critical failure must require review');
        $this->assertContains('critical_failure_never_silent', $d['reasons']);
    }

    /** Repeated failure signature short-circuits to escalation (no blind loop). */
    public function test_repeated_signature_escalates(): void
    {
        $d = $this->service()->decide([
            'failure_domain' => 'tool.execution_failed',
            'severity' => 'high',
            'evidence' => ['log:tail'],
            'attempt' => 1,
            'signature_repeated' => true,
            'policy' => ['max_attempts' => 3],
        ]);

        $this->assertSame(AtlasRepairEscalationService::ACTION_ESCALATE, $d['action']);
        $this->assertContains('repeated_failure_signature', $d['reasons']);
    }

    /** Every decision is auditable and carries the stable receipt schema. */
    public function test_decision_is_auditable_with_stable_schema(): void
    {
        $d = $this->service()->decide(['failure_domain' => 'gate.failed']);

        $this->assertSame('atlas.kernel.repair_escalation.v1', $d['schema']);
        $this->assertTrue($d['auditable']);
    }
}

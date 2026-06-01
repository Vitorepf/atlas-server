<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasQualityGatesService;
use Tests\TestCase;

/**
 * Pins the documented quality-gate decision rules.
 *
 * @see docs/engineering-knowledge-base/system-graph/quality-gates.md
 * @see docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
 */
class AtlasQualityGatesTest extends TestCase
{
    private function service(): AtlasQualityGatesService
    {
        return new AtlasQualityGatesService();
    }

    /**
     * A fully green execution with persisted evidence => pass + resolved +
     * can_promote. (blueprint "Gate Final De Uma Task" / "Estados De Decisao".)
     */
    public function test_all_green_with_evidence_passes_and_promotes(): void
    {
        $d = $this->service()->evaluate([
            'required_tests_ran' => true,
            'evidence_persisted' => true,
            'acceptance_criteria' => [
                ['status' => 'passed', 'evidence' => 'test:case-1'],
            ],
            'review_findings' => [
                ['severity' => 'p2', 'status' => 'open'],
                ['severity' => 'p0', 'status' => 'fixed'],
            ],
            'manual_qa' => ['required' => false],
            'postgres_gate' => ['required' => false],
            'telemetry' => [
                'trace_id' => 't-1', 'run_id' => 'r-1',
                'command' => 'pest', 'status' => 'passed', 'decision' => 'resolved',
            ],
        ]);

        $this->assertSame(AtlasQualityGatesService::VERDICT_PASS, $d['verdict']);
        $this->assertSame(AtlasQualityGatesService::STATE_RESOLVED, $d['promotion_state']);
        $this->assertTrue($d['can_promote']);
        // P2 is a risk note, not a blocker; a fixed P0 never blocks.
        $this->assertSame(0, $d['gates']['deep_review']['blocker_count']);
    }

    /**
     * Golden rule: even a clean run cannot promote without persisted evidence
     * ("Gate sem evidencia persistida nao deve promover estado").
     */
    public function test_no_persisted_evidence_yields_evidence_required(): void
    {
        $d = $this->service()->evaluate([
            'required_tests_ran' => true,
            'evidence_persisted' => false,
            'acceptance_criteria' => [
                ['status' => 'passed', 'evidence' => 'test:case-1'],
            ],
            'telemetry' => [
                'trace_id' => 't', 'run_id' => 'r',
                'command' => 'pest', 'status' => 'passed', 'decision' => 'resolved',
            ],
        ]);

        $this->assertSame(AtlasQualityGatesService::VERDICT_EVIDENCE_REQUIRED, $d['verdict']);
        $this->assertFalse($d['can_promote']);
        $this->assertContains('no_persisted_evidence', $d['reasons']);
        // Some acceptance satisfied => partial, not unresolved.
        $this->assertSame(AtlasQualityGatesService::STATE_PARTIAL, $d['promotion_state']);
    }

    /**
     * Deep review threshold: an open P0 always blocks => fail + unsafe, no
     * promotion ("P0 aberto bloqueia sempre").
     */
    public function test_open_p0_finding_fails_and_is_unsafe(): void
    {
        $d = $this->service()->evaluate([
            'required_tests_ran' => true,
            'evidence_persisted' => true,
            'acceptance_criteria' => [['status' => 'passed', 'evidence' => 'e']],
            'review_findings' => [
                ['severity' => 'p0', 'status' => 'open', 'category' => 'correctness'],
            ],
            'telemetry' => [
                'trace_id' => 't', 'run_id' => 'r',
                'command' => 'review', 'status' => 'failed', 'decision' => 'unsafe',
            ],
        ]);

        $this->assertSame(AtlasQualityGatesService::VERDICT_FAIL, $d['verdict']);
        $this->assertSame(AtlasQualityGatesService::STATE_UNSAFE, $d['promotion_state']);
        $this->assertFalse($d['can_promote']);
        $this->assertContains('p0_open', $d['reasons']);
    }

    /**
     * P1 threshold is exact: confidence >= 0.80 blocks; below it is advisory
     * (unless security/data). ("P1 aberto com confidence >= 0.80 bloqueia".)
     */
    public function test_p1_confidence_threshold_is_exact(): void
    {
        $base = [
            'required_tests_ran' => true,
            'evidence_persisted' => true,
            'acceptance_criteria' => [['status' => 'passed', 'evidence' => 'e']],
            'telemetry' => [
                'trace_id' => 't', 'run_id' => 'r',
                'command' => 'review', 'status' => 'ok', 'decision' => 'x',
            ],
        ];

        // 0.80 exactly => blocks => fail.
        $blocks = $this->service()->evaluate($base + [
            'review_findings' => [
                ['severity' => 'p1', 'status' => 'open', 'confidence' => 0.80, 'category' => 'ux'],
            ],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_FAIL, $blocks['verdict']);

        // 0.79 => advisory only => pass (no blocker), but advisory counted.
        $advisory = $this->service()->evaluate($base + [
            'review_findings' => [
                ['severity' => 'p1', 'status' => 'open', 'confidence' => 0.79, 'category' => 'ux'],
            ],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_PASS, $advisory['verdict']);
        $this->assertSame(1, $advisory['gates']['deep_review']['advisory_count']);

        // Low confidence but security category => still blocks ("salvo seguranca").
        $security = $this->service()->evaluate($base + [
            'review_findings' => [
                ['severity' => 'p1', 'status' => 'open', 'confidence' => 0.10, 'category' => 'security'],
            ],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_FAIL, $security['verdict']);
    }

    /**
     * Acceptance gate: a criterion without evidence blocks conclusion and is an
     * evidence gap, not a repair gap ("Criterio sem evidencia bloqueia").
     * accepted_risk without human approval also blocks deep review.
     */
    public function test_missing_acceptance_evidence_and_accepted_risk_block(): void
    {
        $missing = $this->service()->evaluate([
            'required_tests_ran' => true,
            'evidence_persisted' => true,
            'acceptance_criteria' => [
                ['status' => 'passed', 'evidence' => ''], // no evidence
            ],
            'telemetry' => [
                'trace_id' => 't', 'run_id' => 'r',
                'command' => 'c', 'status' => 's', 'decision' => 'd',
            ],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_EVIDENCE_REQUIRED, $missing['verdict']);
        $this->assertContains('acceptance_criterion_missing_evidence', $missing['reasons']);
        $this->assertFalse($missing['can_promote']);

        // accepted_risk only clears with explicit human approval.
        $unapproved = $this->service()->evaluate([
            'required_tests_ran' => true,
            'evidence_persisted' => true,
            'acceptance_criteria' => [['status' => 'passed', 'evidence' => 'e']],
            'review_findings' => [
                ['severity' => 'p1', 'status' => 'accepted_risk', 'human_approved' => false],
            ],
            'telemetry' => [
                'trace_id' => 't', 'run_id' => 'r',
                'command' => 'c', 'status' => 's', 'decision' => 'd',
            ],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_FAIL, $unapproved['verdict']);
        $this->assertContains('accepted_risk_without_human_approval', $unapproved['gates']['deep_review']['blockers']);
    }

    /**
     * A required gate that never ran cannot be declared done => blocked
     * ("IA nao pode declarar pronto quando gate obrigatorio ... nao rodou").
     * A destructive postgres check is unsafe => fail.
     */
    public function test_unrun_gate_blocks_and_postgres_blocking_check_fails(): void
    {
        $unrun = $this->service()->evaluate([
            'required_tests_ran' => false,
            'evidence_persisted' => true,
            'acceptance_criteria' => [['status' => 'passed', 'evidence' => 'e']],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_BLOCKED, $unrun['verdict']);
        $this->assertSame(AtlasQualityGatesService::STATE_BLOCKED, $unrun['promotion_state']);
        $this->assertContains('required_gate_did_not_run', $unrun['reasons']);

        $pg = $this->service()->evaluate([
            'required_tests_ran' => true,
            'evidence_persisted' => true,
            'acceptance_criteria' => [['status' => 'passed', 'evidence' => 'e']],
            'postgres_gate' => [
                'required' => true,
                'checks' => [
                    ['name' => 'rollback', 'blocking' => true],
                    ['name' => 'lock_risk', 'blocking' => false],
                ],
            ],
            'telemetry' => [
                'trace_id' => 't', 'run_id' => 'r',
                'command' => 'db', 'status' => 's', 'decision' => 'd',
            ],
        ]);
        $this->assertSame(AtlasQualityGatesService::VERDICT_FAIL, $pg['verdict']);
        $this->assertContains('rollback', $pg['gates']['postgres']['blocking_checks']);
    }
}

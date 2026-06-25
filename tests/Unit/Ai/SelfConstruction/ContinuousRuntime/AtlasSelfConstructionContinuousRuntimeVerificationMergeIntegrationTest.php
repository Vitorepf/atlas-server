<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
use Tests\TestCase;

final class AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegrationTest extends TestCase
{
    private function evidence(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-1',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'changed_files' => ['app/Foo.php'],
            'gate_outputs' => ['phpunit' => true],
            'evidence_refs' => ['phpunit:t1'],
        ];
    }

    public function test_verify_pass_yields_merge_request_decision(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;

        $req = $svc->buildVerificationRequest($this->evidence());
        $this->assertTrue($req['ready_for_court']);
        $this->assertSame([], $req['blockers']);

        $merge = $svc->buildMergeDecision([
            'passed' => true,
            'false_green_risk' => false,
            'evidence_refs' => ['receipt:r1'],
        ], ['mode' => 'revert_commit']);

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_REQUEST_MERGE, $merge['decision']);
        $this->assertSame([], $merge['blockers']);
    }

    public function test_verify_fail_yields_reject_decision(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $merge = $svc->buildMergeDecision(['passed' => false], ['mode' => 'revert_commit']);

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_REJECT, $merge['decision']);
        $this->assertContains('verification_failed', $merge['blockers']);
    }

    public function test_missing_rollback_plan_blocks_merge_decision_even_when_verification_passed(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $merge = $svc->buildMergeDecision([
            'passed' => true,
            'evidence_refs' => ['r1'],
        ], []);

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK, $merge['decision']);
        $this->assertContains('rollback_plan_missing', $merge['blockers']);
    }

    public function test_false_green_risk_blocks_merge_decision(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $merge = $svc->buildMergeDecision([
            'passed' => true,
            'false_green_risk' => true,
            'evidence_refs' => ['r1'],
        ], ['mode' => 'revert_commit']);

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK, $merge['decision']);
        $this->assertContains('false_green_risk_detected', $merge['blockers']);
    }

    public function test_evidence_refs_missing_after_verify_blocks(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $merge = $svc->buildMergeDecision([
            'passed' => true,
            'evidence_refs' => [],
        ], ['mode' => 'revert_commit']);

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK, $merge['decision']);
        $this->assertContains('evidence_refs_missing_post_verify', $merge['blockers']);
    }

    public function test_verification_request_with_changed_files_outside_allowed_scope_blocks(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $req = $svc->buildVerificationRequest($this->evidence([
            'changed_files' => ['app/Foo.php', 'config/atlas.php'],
        ]));

        $this->assertFalse($req['ready_for_court']);
        $this->assertContains('changed_files_outside_allowed_scope', $req['blockers']);
    }

    public function test_verification_request_with_missing_evidence_blocks(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $req = $svc->buildVerificationRequest($this->evidence(['evidence_refs' => []]));

        $this->assertFalse($req['ready_for_court']);
        $this->assertContains('evidence_refs_missing', $req['blockers']);
    }
}

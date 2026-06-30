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

    public function test_verify_request_blocks_when_gate_outputs_empty(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $req = $svc->buildVerificationRequest($this->evidence(['gate_outputs' => []]));
        $this->assertFalse($req['ready_for_court']);
        $this->assertContains('gate_outputs_missing', $req['blockers']);
    }

    public function test_verify_request_blocks_when_no_gate_output_is_passed(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $req = $svc->buildVerificationRequest($this->evidence(['gate_outputs' => ['phpunit' => false, 'static_analysis' => false]]));
        $this->assertFalse($req['ready_for_court']);
        $this->assertContains('gate_outputs_no_passed_fact', $req['blockers']);
    }

    public function test_verify_request_passes_when_at_least_one_gate_output_is_passed(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $req = $svc->buildVerificationRequest($this->evidence(['gate_outputs' => ['phpunit' => true, 'other' => false]]));
        $this->assertTrue($req['ready_for_court']);
        $this->assertNotContains('gate_outputs_no_passed_fact', $req['blockers']);
    }

    public function test_merge_decision_blocks_when_rollback_mode_is_unknown(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        $merge = $svc->buildMergeDecision(
            ['passed' => true, 'evidence_refs' => ['r1']],
            ['mode' => 'delete_everything'],
        );
        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK, $merge['decision']);
        $this->assertContains('rollback_mode_not_safe', $merge['blockers']);
    }

    public function test_merge_decision_passes_all_safe_rollback_modes(): void
    {
        $svc = new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
        foreach (AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::SAFE_ROLLBACK_MODES as $mode) {
            $merge = $svc->buildMergeDecision(
                ['passed' => true, 'evidence_refs' => ['r1']],
                ['mode' => $mode],
            );
            $this->assertSame(
                AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_REQUEST_MERGE,
                $merge['decision'],
                "safe mode '$mode' must yield request_merge"
            );
        }
    }

    // ── new fields in verification request ───────────────────────────────────

    public function test_verification_request_includes_touched_scopes_runnable_proof_refs_risk_tier_rollback_readiness(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence());

        $this->assertArrayHasKey('touched_scopes', $req);
        $this->assertArrayHasKey('runnable_proof_refs', $req);
        $this->assertArrayHasKey('risk_tier', $req);
        $this->assertArrayHasKey('rollback_readiness', $req);
    }

    public function test_touched_scopes_derived_from_changed_files(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence([
                'changed_files' => ['app/Services/Foo.php', 'app/Services/Bar.php', 'tests/FooTest.php'],
                'allowed_files' => ['app/Services/Foo.php', 'app/Services/Bar.php', 'tests/FooTest.php'],
            ]));

        $this->assertContains('app/Services', $req['touched_scopes']);
        $this->assertContains('tests', $req['touched_scopes']);
        $this->assertCount(2, $req['touched_scopes']); // deduped
    }

    public function test_risk_tier_low_for_single_change_in_larger_allowed_set(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence([
                'allowed_files' => ['app/A.php', 'app/B.php', 'app/C.php', 'app/D.php', 'tests/T.php'],
                'changed_files' => ['app/A.php'],
            ]));

        $this->assertSame('low', $req['risk_tier']);
    }

    public function test_risk_tier_high_for_many_changes(): void
    {
        $files = ['app/A.php', 'app/B.php', 'app/C.php', 'app/D.php', 'app/E.php', 'app/F.php'];
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence([
                'allowed_files' => $files,
                'changed_files' => $files,
            ]));

        $this->assertSame('high', $req['risk_tier']);
    }

    public function test_runnable_proof_refs_extracted_from_evidence_refs(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence([
                'evidence_refs' => ['phpunit:t1', 'receipt:r1', 'artisan:cmd'],
            ]));

        $this->assertSame(['phpunit:t1', 'artisan:cmd'], $req['runnable_proof_refs']);
    }

    public function test_runnable_proof_refs_missing_blocks_when_evidence_refs_has_no_runnable_refs(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence([
                'evidence_refs' => ['receipt:only-non-runnable'],
            ]));

        $this->assertFalse($req['ready_for_court']);
        $this->assertContains('runnable_proof_refs_missing', $req['blockers']);
    }

    public function test_runnable_proof_refs_missing_does_not_fire_when_evidence_refs_already_empty(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence(['evidence_refs' => []]));

        $this->assertNotContains('runnable_proof_refs_missing', $req['blockers']);
        $this->assertContains('evidence_refs_missing', $req['blockers']);
    }

    public function test_rollback_readiness_surfaced_from_worker_evidence(): void
    {
        $req = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildVerificationRequest($this->evidence(['rollback_readiness' => 'ready']));

        $this->assertSame('ready', $req['rollback_readiness']);
    }

    // ── new merge decision blockers ───────────────────────────────────────────

    public function test_merge_decision_blocks_on_stale_verification(): void
    {
        $merge = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildMergeDecision(
                ['passed' => true, 'stale' => true, 'evidence_refs' => ['r1']],
                ['mode' => 'revert_commit'],
            );

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK, $merge['decision']);
        $this->assertContains('verification_stale', $merge['blockers']);
    }

    public function test_merge_decision_blocks_on_runnable_proof_missing_in_worker_evidence(): void
    {
        $merge = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildMergeDecision(
                ['passed' => true, 'evidence_refs' => ['r1'], 'runnable_proof_refs' => []],
                ['mode' => 'revert_commit'],
            );

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK, $merge['decision']);
        $this->assertContains('runnable_proof_missing_in_worker_evidence', $merge['blockers']);
    }

    public function test_merge_decision_does_not_block_on_runnable_proof_when_key_absent(): void
    {
        $merge = (new AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration)
            ->buildMergeDecision(
                ['passed' => true, 'evidence_refs' => ['r1']],
                ['mode' => 'revert_commit'],
            );

        $this->assertNotContains('runnable_proof_missing_in_worker_evidence', $merge['blockers']);
        $this->assertSame(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_REQUEST_MERGE, $merge['decision']);
    }
}

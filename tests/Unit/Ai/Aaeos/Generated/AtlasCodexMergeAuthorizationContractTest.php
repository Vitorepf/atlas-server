<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeAuthorizationContractService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge Authorization Contract boundary, dependency
 * gating and conservative-default rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
 */
class AtlasCodexMergeAuthorizationContractTest extends TestCase
{
    private function service(): AtlasCodexMergeAuthorizationContractService
    {
        return new AtlasCodexMergeAuthorizationContractService();
    }

    /**
     * A checklist input that satisfies every one of the 8 documented
     * prerequisites, making the checklist ready.
     *
     * @return array<string,bool>
     */
    private function fullChecklistInput(): array
    {
        $input = [];
        foreach (AtlasCodexMergeAuthorizationContractService::CHECKLIST_REQUIRED_PREREQUISITES as $prereq) {
            $input[$prereq] = true;
        }

        return $input;
    }

    /**
     * Doc "must keep signature_valid=false, approval_granted=false and
     * merge_allowed=false" — with empty (safe-default) input every surface keeps
     * its guarded boundary keys false, the boundary holds, and the chain is
     * NOT ready (no prerequisite satisfied).
     */
    public function test_empty_input_keeps_boundary_false_and_chain_not_ready(): void
    {
        $r = $this->service()->contract([]);

        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['chain_ready']);
        $this->assertFalse($r['merge_allowed']);

        // Every surface that exposes a guarded key keeps it exactly false.
        foreach ([
            'merge_execution_checklist',
            'merge_authorization_template',
            'merge_authorization_receipt_draft',
            'merge_authorization_signature_request',
            'merge_authorization_post_signature_runbook',
            'merge_final_authorization_preflight',
        ] as $surface) {
            $this->assertFalse($r[$surface]['merge_allowed'], "$surface merge_allowed must be false");
            $this->assertFalse($r[$surface]['signature_valid'], "$surface signature_valid must be false");
            $this->assertFalse($r[$surface]['approval_granted'], "$surface approval_granted must be false");
        }

        // Receipt draft / signature request additionally keep these false.
        $this->assertFalse($r['merge_authorization_receipt_draft']['signature_present']);
        $this->assertFalse($r['merge_authorization_receipt_draft']['decision_recorded']);
        // Final preflight additionally keeps authorization_ready false.
        $this->assertFalse($r['merge_final_authorization_preflight']['authorization_ready']);
    }

    /**
     * Doc "It may become ready only after the post-signature runbook is ready"
     * (and the whole transitive chain). A single missing checklist prerequisite
     * must keep the checklist not-ready and gate EVERY downstream surface with
     * its exact documented gated_reason.
     */
    public function test_one_missing_prerequisite_gates_the_entire_chain(): void
    {
        $input = $this->fullChecklistInput();
        // Drop exactly one documented prerequisite.
        unset($input['rollback_plan']);

        $r = $this->service()->contract(['execution_checklist' => $input]);

        $this->assertFalse($r['merge_execution_checklist']['ready']);
        $this->assertContains('rollback_plan', $r['merge_execution_checklist']['missing_prerequisites']);

        $this->assertFalse($r['merge_authorization_template']['ready']);
        $this->assertSame('merge_execution_checklist_not_ready', $r['merge_authorization_template']['gated_reason']);

        $this->assertFalse($r['merge_authorization_receipt_draft']['ready']);
        $this->assertSame('merge_authorization_template_not_ready', $r['merge_authorization_receipt_draft']['gated_reason']);

        $this->assertFalse($r['merge_authorization_signature_request']['ready']);
        $this->assertSame('gated', $r['merge_authorization_signature_request']['status']);

        $this->assertFalse($r['merge_final_authorization_preflight']['ready']);
        $this->assertFalse($r['chain_ready']);
    }

    /**
     * Doc "The required default is request_changes" — a clean template input
     * resolves to request_changes, never merge.
     */
    public function test_default_decision_is_request_changes_when_clean(): void
    {
        $svc = $this->service();

        $this->assertSame('request_changes', $svc->defaultDecision([]));

        $template = $svc->mergeAuthorizationTemplate(['ready' => true], []);
        $this->assertSame('request_changes', $template['default_decision']);
        $this->assertContains('merge', $template['allowed_decisions']);
        $this->assertNotSame('merge', $template['default_decision']);
    }

    /**
     * Doc "hash mismatch, evidence failure or hot-scope changes must default to
     * abort." Each of the three conditions independently forces abort.
     */
    public function test_integrity_failure_forces_abort_default(): void
    {
        $svc = $this->service();

        $this->assertSame('abort', $svc->defaultDecision(['hash_mismatch' => true]));
        $this->assertSame('abort', $svc->defaultDecision(['evidence_failure' => true]));
        $this->assertSame('abort', $svc->defaultDecision(['hot_scope_change' => true]));

        // It propagates into the template surface's default decision.
        $template = $svc->mergeAuthorizationTemplate(['ready' => true], ['hot_scope_change' => true]);
        $this->assertSame('abort', $template['default_decision']);
    }

    /**
     * Doc full chain: with every prerequisite satisfied AND every downstream gate
     * cleared, each surface becomes ready/pending in dependency order, the chain
     * reports ready — yet merge_allowed and signature_valid STAY false and the
     * boundary still holds (the surfaces never authorize or merge themselves).
     */
    public function test_full_satisfied_chain_becomes_ready_but_never_authorizes(): void
    {
        $r = $this->service()->contract([
            'execution_checklist' => $this->fullChecklistInput(),
        ]);

        $this->assertTrue($r['merge_execution_checklist']['ready']);
        $this->assertTrue($r['merge_authorization_template']['ready']);
        $this->assertTrue($r['merge_authorization_receipt_draft']['ready']);

        // The signature request becomes pending (signable prepared), never signed.
        $this->assertSame('pending', $r['merge_authorization_signature_request']['status']);
        $this->assertTrue($r['merge_authorization_signature_request']['ready']);

        $this->assertTrue($r['merge_authorization_post_signature_runbook']['ready']);
        $this->assertTrue($r['merge_final_authorization_preflight']['ready']);
        $this->assertTrue($r['chain_ready']);

        // Hard boundary survives a fully-ready chain.
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['merge_allowed']);
        $this->assertFalse($r['merge_final_authorization_preflight']['authorization_ready']);
        $this->assertFalse($r['merge_authorization_signature_request']['signature_valid']);
        $this->assertFalse($r['merge_authorization_signature_request']['merge_allowed']);
    }

    /**
     * Doc "It may become ready only after the authorization receipt draft is
     * ready" / "after the signature request is pending". The runbook must NOT be
     * ready while the signature request is merely gated, and the preflight must
     * stay gated behind the runbook — proving the strict ordering.
     */
    public function test_runbook_and_preflight_require_pending_signature_request(): void
    {
        $svc = $this->service();

        // A gated (not pending) signature request must not let the runbook open.
        $gatedRequest = $svc->mergeAuthorizationSignatureRequest(['ready' => false], []);
        $runbook = $svc->mergeAuthorizationPostSignatureRunbook($gatedRequest, []);
        $this->assertFalse($runbook['ready']);
        $this->assertSame('merge_authorization_signature_request_not_pending', $runbook['gated_reason']);

        $preflight = $svc->mergeFinalAuthorizationPreflight($runbook, []);
        $this->assertFalse($preflight['ready']);
        $this->assertSame('merge_authorization_post_signature_runbook_not_ready', $preflight['gated_reason']);
        $this->assertFalse($preflight['merge_allowed']);
    }
}

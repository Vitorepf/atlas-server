<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiContinuitySessionStateService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI continuity contract: the six session-state
 * continuation verdicts ("Estados De Sessao"), the minimum snapshot core
 * ("Contrato Minimo De Session Snapshot"), the compaction preserve/drop lists
 * ("Compactacao"), the failure-mode treatments ("Failure Modes") and the
 * handoff invariant (a handoff needs a new parent-linked receipt).
 *
 * @see docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
 */
class AtlasAiContinuitySessionStateTest extends TestCase
{
    private function service(): AtlasAiContinuitySessionStateService
    {
        return new AtlasAiContinuitySessionStateService();
    }

    /**
     * "Estados De Sessao": active = continue freely; archived = NO without
     * human revalidation; handoff = yes but requires a new receipt; closed =
     * only as a new related operation; unknown state must not continue.
     */
    public function test_state_continuation_verdicts_match_doc_table(): void
    {
        $s = $this->service();

        $active = $s->evaluateContinuation('active');
        $this->assertTrue($active['can_continue']);
        $this->assertSame(AtlasAiContinuitySessionStateService::CONTINUE_YES, $active['verdict']);
        $this->assertFalse($active['requires_new_receipt']);

        $archived = $s->evaluateContinuation('archived');
        $this->assertFalse($archived['can_continue']);
        $this->assertTrue($archived['requires_human_revalidation']);
        $this->assertSame(
            AtlasAiContinuitySessionStateService::CONTINUE_NO_WITHOUT_HUMAN_REVALIDATION,
            $archived['verdict'],
        );

        $handoff = $s->evaluateContinuation('handoff');
        $this->assertTrue($handoff['can_continue']);
        $this->assertTrue($handoff['requires_new_receipt']);

        $closed = $s->evaluateContinuation('closed');
        $this->assertSame(
            AtlasAiContinuitySessionStateService::CONTINUE_NEW_RELATED_OPERATION_ONLY,
            $closed['verdict'],
        );

        // Unknown state is fail-safe: cannot continue.
        $unknown = $s->evaluateContinuation('zombie');
        $this->assertFalse($unknown['known_state']);
        $this->assertFalse($unknown['can_continue']);
    }

    /**
     * "Contrato Minimo De Session Snapshot": session_id + intent are the hard
     * core. A snapshot missing the objective is invalid; a snapshot with the
     * core present is valid even when recommended fields are absent.
     */
    public function test_snapshot_requires_session_id_and_intent(): void
    {
        $s = $this->service();

        $missing = $s->validateSnapshot(['session_id' => 'sess_1']);
        $this->assertFalse($missing['valid']);
        $this->assertContains('intent', $missing['missing_required']);

        $ok = $s->validateSnapshot(['session_id' => 'sess_1', 'intent' => 'ship the fix']);
        $this->assertTrue($ok['valid']);
        $this->assertSame([], $ok['missing_required']);
        // Recommended fields like evidence_refs are still reported as missing.
        $this->assertContains('evidence_refs', $ok['missing_recommended']);
    }

    /**
     * "Compactacao": intent / decisions / evidence_refs / context_hash MUST be
     * preserved; raw_prompt / secrets / verbatim provider output MUST be
     * dropped by default. An empty compaction is non-compliant because it
     * dropped fields it was required to preserve.
     */
    public function test_compaction_preserve_and_drop_lists(): void
    {
        $s = $this->service();
        $c = $s->classifyCompaction([]);

        $this->assertContains('intent', $c['must_preserve']);
        $this->assertContains('decisions', $c['must_preserve']);
        $this->assertContains('evidence_refs', $c['must_preserve']);
        $this->assertContains('context_hash', $c['must_preserve']);

        $this->assertContains('raw_prompt', $c['drop_by_default']);
        $this->assertContains('secrets_tokens_envs_paths', $c['drop_by_default']);
        $this->assertContains('verbatim_provider_output', $c['drop_by_default']);

        // Nothing preserved => not compliant.
        $this->assertFalse($c['compliant']);
        $this->assertContains('intent', $c['missing_preserved']);
    }

    /**
     * A leaked secret in the compacted payload is flagged as a drop-by-default
     * violation even if every preserved field is present.
     */
    public function test_compaction_flags_leaked_secrets(): void
    {
        $s = $this->service();
        $c = $s->classifyCompaction([
            'intent' => 'x',
            'decisions' => ['a'],
            'rejected_alternatives' => ['b'],
            'evidence_refs' => ['r1'],
            'relevant_files_commands_routes' => ['f'],
            'operator_constraints' => ['c'],
            'pending_risks_gates' => ['risk'],
            'provider_model_surface' => ['p'],
            'context_hash' => 'abc',
            'refresh_or_reuse_reason' => 'reuse',
            'secrets_tokens_envs_paths' => 'AWS_KEY=leak', // must NOT be here
        ]);

        $this->assertSame([], $c['missing_preserved']);
        $this->assertContains('secrets_tokens_envs_paths', $c['leaked_dropped']);
        $this->assertFalse($c['compliant']);
    }

    /**
     * "Failure Modes": provider changed without receipt => register violation
     * and may NOT act; pending privacy => continue but degraded to read/plan
     * only; stale context without hash => may not act until regen.
     */
    public function test_failure_modes_match_doc_treatments(): void
    {
        $s = $this->service();

        $noReceipt = $s->handleFailureMode('provider_changed_without_receipt');
        $this->assertFalse($noReceipt['may_act']);
        $this->assertSame(
            AtlasAiContinuitySessionStateService::ACT_REGISTER_VIOLATION_NEW_RECEIPT,
            $noReceipt['action'],
        );

        $privacy = $s->handleFailureMode('session_with_pending_privacy');
        $this->assertTrue($privacy['may_act']);
        $this->assertSame('read_or_plan_only', $privacy['degrade_to']);

        $stale = $s->handleFailureMode('stale_context_without_hash');
        $this->assertFalse($stale['may_act']);

        // Unknown failure is fail-safe.
        $unknown = $s->handleFailureMode('mystery');
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['may_act']);
    }

    /**
     * Handoff invariant + full resolution: a handoff snapshot WITHOUT a
     * parent-linked receipt must be blocked (it injects the
     * provider_changed_without_receipt violation), while a complete active
     * snapshot with no failures may execute.
     */
    public function test_resolve_continuation_enforces_handoff_invariant(): void
    {
        $s = $this->service();

        $blocked = $s->resolveContinuation([
            'state' => AtlasAiContinuitySessionStateService::STATE_HANDOFF,
            'session_id' => 'sess_2',
            'intent' => 'continue migration',
            // no decision_receipt_id, no parent_session_id
        ]);
        $this->assertTrue($blocked['blocked']);
        $this->assertFalse($blocked['may_execute']);
        $this->assertContains('handoff_requires_parent_linked_receipt', $blocked['reasons']);

        $allowed = $s->resolveContinuation([
            'state' => AtlasAiContinuitySessionStateService::STATE_ACTIVE,
            'session_id' => 'sess_3',
            'intent' => 'finish the feature',
        ]);
        $this->assertFalse($allowed['blocked']);
        $this->assertTrue($allowed['may_execute']);
        $this->assertCount(6, $allowed['required_steps']);
    }
}

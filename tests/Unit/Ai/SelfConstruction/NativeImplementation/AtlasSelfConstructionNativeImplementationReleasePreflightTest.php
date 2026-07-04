<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeImplementationReleasePreflight;
use Tests\TestCase;

final class AtlasSelfConstructionNativeImplementationReleasePreflightTest extends TestCase
{
    private function happyProposal(array $overrides = []): array
    {
        return $overrides + [
            'changed_files'             => ['app/Foo.php'],
            'allowed_files'             => ['app/Foo.php', 'tests/FooTest.php'],
            'forbidden_targets'         => ['config/atlas.php'],
            'required_evidence'         => ['phpunit', 'rollback_plan'],
            'evidence_refs'             => ['phpunit:t1', 'rollback_plan:revert_commit', 'bounded_rollback:sha-bounded'],
            'rollback_preimage'         => ['app/Foo.php' => 'sha-prev'],
            'merge_governor'            => ['high_risk_change' => false],
            'final_runtime_owner'       => 'atlas_native',
            'requires_human'            => false,
            'requires_operator'         => false,
            'requires_external_provider'=> false,
        ];
    }

    public function test_allowed_diff_with_full_evidence_returns_allow(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal());

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_ALLOW, $verdict['decision']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_outside_scope_diff_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'changed_files' => ['app/Foo.php', 'lib/Bar.php'],
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('change_outside_allowed_files:lib/Bar.php', $verdict['blockers']);
    }

    public function test_forbidden_target_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'changed_files' => ['app/Foo.php', 'config/atlas.php'],
            'allowed_files' => ['app/Foo.php', 'config/atlas.php'], // ostensibly allowed, but also forbidden
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('change_in_forbidden_targets:config/atlas.php', $verdict['blockers']);
    }

    public function test_missing_rollback_preimage_returns_needs_more_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'rollback_preimage' => [],
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_NEEDS_MORE_EVIDENCE, $verdict['decision']);
        $this->assertContains('missing_rollback_preimage:app/Foo.php', $verdict['blockers']);
    }

    public function test_missing_verification_evidence_returns_needs_more_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'evidence_refs' => ['rollback_plan:revert_commit'], // missing phpunit
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_NEEDS_MORE_EVIDENCE, $verdict['decision']);
        $this->assertContains('missing_evidence_kinds:phpunit', $verdict['blockers']);
    }

    public function test_high_risk_change_requires_extra_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'merge_governor' => ['high_risk_change' => true, 'extra_evidence_required' => ['mutop_kill']],
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_NEEDS_MORE_EVIDENCE, $verdict['decision']);
        $this->assertContains('high_risk_missing_evidence:mutop_kill', $verdict['blockers']);
    }

    public function test_scope_violation_dominates_missing_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'changed_files' => ['app/Foo.php', 'lib/Bar.php'],
            'rollback_preimage' => [],
        ]));

        // Reject wins over needs_more_evidence.
        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
    }

    public function test_empty_changed_files_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'changed_files' => [],
            'rollback_preimage' => [],
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('empty_changed_files', $verdict['blockers']);
    }

    public function test_forbidden_target_alias_is_rejected(): void
    {
        // ./config/atlas.php is an alias of config/atlas.php — must still be caught.
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight($this->happyProposal([
            'changed_files' => ['./config/atlas.php'],
            'allowed_files' => ['./config/atlas.php'],
            'forbidden_targets' => ['config/atlas.php'],
            'rollback_preimage' => ['./config/atlas.php' => 'sha-prev'],
        ]));

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('change_in_forbidden_targets:./config/atlas.php', $verdict['blockers']);
    }

    public function test_preflight_does_not_apply_diff(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionNativeImplementationReleasePreflight.php'));
        foreach (['file_put_contents', 'shell_exec', 'exec(', 'system(', 'proc_open', 'git '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src);
        }
    }

    // ── autonomy proof floor ──────────────────────────────────────────────────

    public function test_requires_human_true_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal(['requires_human' => true])
        );

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('autonomy_violation:requires_human_must_be_false', $verdict['blockers']);
    }

    public function test_requires_operator_true_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal(['requires_operator' => true])
        );

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('autonomy_violation:requires_operator_must_be_false', $verdict['blockers']);
    }

    public function test_requires_external_provider_true_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal(['requires_external_provider' => true])
        );

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('autonomy_violation:requires_external_provider_must_be_false', $verdict['blockers']);
    }

    public function test_non_atlas_native_final_runtime_owner_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal(['final_runtime_owner' => 'claude_code'])
        );

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('autonomy_violation:final_runtime_owner_not_atlas_native:claude_code', $verdict['blockers']);
    }

    public function test_missing_bounded_rollback_evidence_returns_needs_more_evidence(): void
    {
        // Has tests and rollback_preimage but no bounded_rollback in evidence_refs.
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal(['evidence_refs' => ['phpunit:t1', 'rollback_plan:revert_commit']])
        );

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_NEEDS_MORE_EVIDENCE, $verdict['decision']);
        $this->assertContains('autonomy_proof_floor_missing:bounded_rollback', $verdict['blockers']);
    }

    public function test_scope_violation_dominates_autonomy_floor_missing(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal([
                'changed_files' => ['app/Foo.php', 'lib/Bar.php'],
                'evidence_refs' => ['phpunit:t1', 'rollback_plan:r'],  // no bounded_rollback
            ])
        );

        // REJECT wins over NEEDS_MORE.
        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_REJECT, $verdict['decision']);
    }

    public function test_all_autonomy_floor_fields_correct_returns_allow(): void
    {
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal()  // happyProposal already has the full floor
        );

        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_ALLOW, $verdict['decision']);
        $this->assertSame([], $verdict['blockers']);
    }

    /**
     * An evidence ref with a leading colon (e.g. ":tests_or_gates_result") must
     * parse to an empty kind — not the whole ref — so the missing-evidence verdict
     * is correct. Previously, strpos(kind, ':') returned 0, and 0 ?: null collapsed
     * to null, causing substr(kind, 0, null) to return the entire string.
     */
    public function test_leading_colon_evidence_ref_parses_to_empty_kind(): void
    {
        // The evidence ref ":tests_or_gates_result" has a colon at position 0.
        // The kind should parse to "" (empty string), not ":tests_or_gates_result".
        $verdict = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->preflight(
            $this->happyProposal([
                'evidence_refs' => [
                    ':tests_or_gates_result',  // leading colon → empty kind
                    'rollback_plan:revert_commit',
                    'bounded_rollback:sha-bounded',
                ],
            ])
        );

        // The empty kind "" does NOT cover "phpunit", so phpunit is still missing.
        $this->assertSame(AtlasSelfConstructionNativeImplementationReleasePreflight::DECISION_NEEDS_MORE_EVIDENCE, $verdict['decision']);
        $this->assertContains('missing_evidence_kinds:phpunit', $verdict['blockers']);
    }

    // ── releasePreflight: release_ready, blocking_gates, evidence_refs, next_unblock_action ──

    public function test_all_gates_pass_release_ready(): void
    {
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.9,
            'proof_freshness' => 0.8,
            'scope_isolation' => true,
            'rollback_readiness' => true,
            'knowledge_sync_readiness' => true,
            'evidence_refs' => ['phpunit:t1', 'rollback_plan:r1'],
        ]);
        $this->assertTrue($r['release_ready']);
        $this->assertSame([], $r['blocking_gates']);
        $this->assertSame('proceed_with_release', $r['next_unblock_action']);
    }

    public function test_low_task_quality_blocks_release(): void
    {
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.3,
            'proof_freshness' => 0.8,
            'scope_isolation' => true,
            'rollback_readiness' => true,
            'knowledge_sync_readiness' => true,
            'evidence_refs' => ['phpunit:t1'],
        ]);
        $this->assertFalse($r['release_ready']);
        $this->assertStringContainsString('task_quality=', $r['blocking_gates'][0]);
        $this->assertSame('address_blocking_gates', $r['next_unblock_action']);
    }

    public function test_missing_scope_isolation_blocks_release(): void
    {
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.9,
            'proof_freshness' => 0.8,
            'scope_isolation' => false,
            'rollback_readiness' => true,
            'knowledge_sync_readiness' => true,
            'evidence_refs' => ['phpunit:t1'],
        ]);
        $this->assertFalse($r['release_ready']);
        $this->assertContains('scope_isolation_not_verified', $r['blocking_gates']);
        $this->assertSame('verify_scope_isolation', $r['next_unblock_action']);
    }

    public function test_missing_rollback_readiness_blocks_release(): void
    {
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.9,
            'proof_freshness' => 0.8,
            'scope_isolation' => true,
            'rollback_readiness' => false,
            'knowledge_sync_readiness' => true,
            'evidence_refs' => ['phpunit:t1'],
        ]);
        $this->assertFalse($r['release_ready']);
        $this->assertContains('rollback_readiness_missing', $r['blocking_gates']);
        $this->assertSame('prepare_rollback_plan', $r['next_unblock_action']);
    }

    public function test_missing_knowledge_sync_blocks_release(): void
    {
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.9,
            'proof_freshness' => 0.8,
            'scope_isolation' => true,
            'rollback_readiness' => true,
            'knowledge_sync_readiness' => false,
            'evidence_refs' => ['phpunit:t1'],
        ]);
        $this->assertFalse($r['release_ready']);
        $this->assertContains('knowledge_sync_readiness_missing', $r['blocking_gates']);
        $this->assertSame('sync_knowledge_base', $r['next_unblock_action']);
    }

    public function test_empty_evidence_refs_blocks_release(): void
    {
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.9,
            'proof_freshness' => 0.8,
            'scope_isolation' => true,
            'rollback_readiness' => true,
            'knowledge_sync_readiness' => true,
            'evidence_refs' => [],
        ]);
        $this->assertFalse($r['release_ready']);
        $this->assertContains('no_provider_safe_evidence_refs', $r['blocking_gates']);
    }

    public function test_evidence_refs_passed_through(): void
    {
        $refs = ['phpunit:t1', 'rollback_plan:r1', 'bounded_rollback:b1'];
        $r = (new AtlasSelfConstructionNativeImplementationReleasePreflight)->releasePreflight([
            'task_quality' => 0.9,
            'proof_freshness' => 0.8,
            'scope_isolation' => true,
            'rollback_readiness' => true,
            'knowledge_sync_readiness' => true,
            'evidence_refs' => $refs,
        ]);
        $this->assertSame($refs, $r['evidence_refs']);
    }
}

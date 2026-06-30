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
            'changed_files' => ['app/Foo.php'],
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'forbidden_targets' => ['config/atlas.php'],
            'required_evidence' => ['phpunit', 'rollback_plan'],
            'evidence_refs' => ['phpunit:t1', 'rollback_plan:revert_commit'],
            'rollback_preimage' => ['app/Foo.php' => 'sha-prev'],
            'merge_governor' => ['high_risk_change' => false],
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
}

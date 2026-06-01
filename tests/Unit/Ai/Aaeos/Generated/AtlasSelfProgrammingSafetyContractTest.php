<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfProgrammingSafetyContractService as Contract;
use Tests\TestCase;

/**
 * Pins the documented Self-Programming Safety Contract decision rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
 */
class AtlasSelfProgrammingSafetyContractTest extends TestCase
{
    private function service(): Contract
    {
        return new Contract();
    }

    /** @return array<string,bool> all 8 preconditions met */
    private function allPreconditions(): array
    {
        return [
            'docs_current' => true,
            'context_fresh' => true,
            'spec_present' => true,
            'files_declared' => true,
            'gates_runnable' => true,
            'rollback_present' => true,
            'evidence_known' => true,
            'drift_reviewable' => true,
        ];
    }

    /**
     * Required Preconditions: self-programming is allowed only when ALL eight
     * hold; a single missing precondition forbids it and is reported.
     */
    public function test_preconditions_require_all_eight(): void
    {
        $all = $this->service()->preconditions($this->allPreconditions());
        $this->assertTrue($all['all_met']);
        $this->assertTrue($all['self_programming_allowed']);
        $this->assertSame(0, $all['missing_count']);

        $missingRollback = $this->allPreconditions();
        $missingRollback['rollback_present'] = false;
        $one = $this->service()->preconditions($missingRollback);
        $this->assertFalse($one['all_met']);
        $this->assertFalse($one['self_programming_allowed']);
        $this->assertSame(['rollback_present'], $one['unmet']);
    }

    /**
     * Forbidden Mutations: each of the nine targets needs a human gate and can
     * never auto-apply; a benign target stays auto-applicable.
     */
    public function test_forbidden_mutations_require_human_gate(): void
    {
        foreach (['auth_security_boundary', 'provider_model_selection_policy', 'irreversible_data_change'] as $target) {
            $c = $this->service()->classifyMutation($target);
            $this->assertTrue($c['forbidden'], "{$target} must be forbidden");
            $this->assertTrue($c['requires_human_gate']);
            $this->assertFalse($c['auto_apply_allowed']);
        }

        // Free-form path mapped to a forbidden class via needle.
        $needle = $this->service()->classifyMutation('app/Services/Memory/MemoryPrivacyPolicy.php');
        $this->assertTrue($needle['forbidden']);
        $this->assertSame('memory_deletion_promotion_or_privacy_policy', $needle['forbidden_class']);

        // A benign refactor target is not forbidden.
        $benign = $this->service()->classifyMutation('update_readme_typo');
        $this->assertFalse($benign['forbidden']);
        $this->assertTrue($benign['auto_apply_allowed']);
    }

    /**
     * Autonomy Shrink Rule table: each condition maps to its documented
     * maximum autonomy, and the ceiling is the LEAST-autonomy active level.
     */
    public function test_autonomy_shrink_table_rows(): void
    {
        $svc = $this->service();
        // no risk -> full execute
        $this->assertSame(Contract::AUTONOMY_EXECUTE, $svc->autonomyCeiling([])['ceiling']);
        $this->assertSame(Contract::AUTONOMY_PROPOSE, $svc->autonomyCeiling(['docs_stale' => true])['ceiling']);
        $this->assertSame(Contract::AUTONOMY_ASK, $svc->autonomyCeiling(['context_missing' => true])['ceiling']);
        $this->assertSame(Contract::AUTONOMY_HUMAN_APPROVAL, $svc->autonomyCeiling(['high_risk' => true])['ceiling']);
        $this->assertSame(Contract::AUTONOMY_DOCS_SPEC_ONLY, $svc->autonomyCeiling(['no_tests' => true])['ceiling']);
        $this->assertSame(Contract::AUTONOMY_REPAIR_IN_SCOPE, $svc->autonomyCeiling(['validation_failed' => true])['ceiling']);

        // no_rollback and forbidden_files are hard blocks ("no execution").
        $this->assertSame(Contract::AUTONOMY_BLOCK, $svc->autonomyCeiling(['no_rollback' => true])['ceiling']);
        $this->assertSame(Contract::AUTONOMY_BLOCK, $svc->autonomyCeiling(['forbidden_files_involved' => true])['ceiling']);
    }

    /**
     * "When uncertainty rises, autonomy falls": with several conditions active
     * the ceiling collapses to the most restrictive one (block), never the
     * lenient one.
     */
    public function test_autonomy_collapses_to_most_restrictive(): void
    {
        $r = $this->service()->autonomyCeiling([
            'docs_stale' => true,        // propose_only
            'high_risk' => true,         // human_approval
            'no_rollback' => true,       // block  <-- wins
        ]);
        $this->assertSame(Contract::AUTONOMY_BLOCK, $r['ceiling']);
        $this->assertFalse($r['may_execute']);
        $this->assertContains('docs_stale', $r['active_conditions']);
        $this->assertContains('no_rollback', $r['active_conditions']);
    }

    /**
     * Top-level decide(): clean preconditions + benign target + zero risk =>
     * full execute auto-apply; flipping any single guard removes auto-apply.
     */
    public function test_decide_only_auto_applies_when_everything_is_clean(): void
    {
        $clean = $this->service()->decide([
            'preconditions' => $this->allPreconditions(),
            'mutation_target' => 'rename_local_variable',
            'conditions' => [],
        ]);
        $this->assertSame(Contract::AUTONOMY_EXECUTE, $clean['autonomy_ceiling']);
        $this->assertTrue($clean['may_auto_apply']);
        $this->assertTrue($clean['may_proceed']);
        $this->assertFalse($clean['requires_human_gate']);

        // Forbidden target alone -> human gate, no auto-apply, still proceeds (gated).
        $forbidden = $this->service()->decide([
            'preconditions' => $this->allPreconditions(),
            'mutation_target' => 'auth_security_boundary',
            'conditions' => [],
        ]);
        $this->assertSame(Contract::AUTONOMY_HUMAN_APPROVAL, $forbidden['autonomy_ceiling']);
        $this->assertFalse($forbidden['may_auto_apply']);
        $this->assertTrue($forbidden['requires_human_gate']);
        $this->assertTrue($forbidden['may_proceed']);

        // Missing rollback -> hard block: cannot proceed at all.
        $blocked = $this->service()->decide([
            'preconditions' => $this->allPreconditions(),
            'mutation_target' => 'rename_local_variable',
            'conditions' => ['no_rollback' => true],
        ]);
        $this->assertSame(Contract::AUTONOMY_BLOCK, $blocked['autonomy_ceiling']);
        $this->assertFalse($blocked['may_auto_apply']);
        $this->assertFalse($blocked['may_proceed']);
    }

    /**
     * Patch Shape, Receipt Scope and Safety Closeout completeness gates each
     * enforce the documented field sets.
     */
    public function test_patch_scope_and_closeout_completeness(): void
    {
        $svc = $this->service();

        $patch = $svc->scorePatch(['small' => true, 'reversible' => true, 'localized' => true]);
        $this->assertSame(3, $patch['score']);
        $this->assertSame(7, $patch['max_score']);
        $this->assertFalse($patch['well_shaped']);

        $scope = $svc->receiptScopeKeys(['max_files_changed' => 3, 'rollback_strategy' => 'git_revert']);
        $this->assertFalse($scope['complete']);
        $this->assertContains('evidence_required', $scope['missing_keys']);
        $this->assertSame(8, count($scope['required_keys']));

        $closeout = $svc->closeout([
            'what_changed' => 'patched x',
            'why_safe' => 'inside scope',
            'gates_run' => 'docs-health',
            'evidence_recorded' => 'receipt-1',
            'what_not_touched' => 'kernel',
            'residual_risk' => 'low',
            // missing next_recommended_maturity_step
        ]);
        $this->assertFalse($closeout['complete']);
        $this->assertSame(['next_recommended_maturity_step'], $closeout['missing_fields']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueOutcomeEvidenceEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasGoalValueOutcomeEvidenceEvaluator: each value class needs an EXPLICIT receipt+supporting
 * ref to be confirmed; missing receipt ⇒ status=unknown; receipt present but supporting ref absent ⇒
 * status=blocked; absence is NEVER converted into inferred success.
 */
final class AtlasGoalValueOutcomeEvidenceEvaluatorTest extends TestCase
{
    public function test_capability_lift_confirmed_with_receipt_verification_and_delta(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']],
            'verification' => ['server_side_green' => true, 'ref' => 'verif-1'],
            'capability_delta' => ['before' => 'feature absent', 'after' => 'feature ships'],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['status']);
        $this->assertContains('rec-1', $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['evidence_refs']);
    }

    public function test_failure_removal_confirmed_with_fix_receipt_and_passing_regression_test_gate(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'fix_failure', 'ref' => 'rec-fix']],
            'gates' => [['kind' => 'regression_test', 'ref' => 'gate-1', 'passed' => true]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_FAILURE_REMOVAL]['status']);
    }

    public function test_autonomy_lift_confirmed_with_receipt_and_learning_ref(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'autonomy_added', 'ref' => 'rec-aut']],
            'learning' => [['kind' => 'autonomy_lift', 'ref' => 'learn-1']],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_AUTONOMY_LIFT]['status']);
    }

    public function test_simplification_confirmed_with_receipt_and_verification(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'simplification', 'ref' => 'rec-simpl']],
            'verification' => ['server_side_green' => true, 'ref' => 'v-1'],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION]['status']);
    }

    public function test_reuse_confirmed_with_receipt_and_passing_code_index_gate(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'reuse_existing', 'ref' => 'rec-reuse']],
            'gates' => [['kind' => 'code_index', 'ref' => 'gate-2', 'passed' => true]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status']);
    }

    public function test_missing_receipt_yields_unknown_not_inferred_success(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([]);
        foreach ($r['value_facts'] as $f) {
            $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_UNKNOWN, $f['status']);
        }
    }

    public function test_receipt_present_but_supporting_ref_missing_yields_blocked(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']],
            // verification absent
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['status']);
    }

    public function test_empty_supporting_ref_yields_blocked_not_confirmed_for_all_classes(): void
    {
        $ev = new AtlasGoalValueOutcomeEvidenceEvaluator;

        // capability_lift: server_side_green=true but ref=''
        $r = $ev->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']],
            'verification' => ['server_side_green' => true, 'ref' => ''],
        ]);
        $by = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $by[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['status'], 'capability_lift: empty verification ref must block');

        // simplification: same shape
        $r = $ev->evaluate([
            'receipts' => [['kind' => 'simplification', 'ref' => 'rec-s']],
            'verification' => ['server_side_green' => true, 'ref' => ''],
        ]);
        $by = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $by[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION]['status'], 'simplification: empty verification ref must block');

        // failure_removal: passing gate but ref=''
        $r = $ev->evaluate([
            'receipts' => [['kind' => 'fix_failure', 'ref' => 'rec-f']],
            'gates' => [['kind' => 'regression_test', 'ref' => '', 'passed' => true]],
        ]);
        $by = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $by[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_FAILURE_REMOVAL]['status'], 'failure_removal: empty gate ref must block');

        // reuse: passing gate but ref=''
        $r = $ev->evaluate([
            'receipts' => [['kind' => 'reuse_existing', 'ref' => 'rec-r']],
            'gates' => [['kind' => 'code_index', 'ref' => '', 'passed' => true]],
        ]);
        $by = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $by[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status'], 'reuse: empty gate ref must block');

        // autonomy_lift: learning kind present but ref=''
        $r = $ev->evaluate([
            'receipts' => [['kind' => 'autonomy_added', 'ref' => 'rec-a']],
            'learning' => [['kind' => 'autonomy_lift', 'ref' => '']],
        ]);
        $by = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $by[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_AUTONOMY_LIFT]['status'], 'autonomy_lift: empty learning ref must block');
    }

    // ── capability_delta gate ─────────────────────────────────────────────────

    public function test_capability_lift_blocked_without_capability_delta(): void
    {
        // Receipt + verification present but no capability_delta → blocked.
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']],
            'verification' => ['server_side_green' => true, 'ref' => 'verif-1'],
            // no capability_delta
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['status']);
        $this->assertStringContainsString('capability_delta missing', $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['reason']);
        $this->assertContains('rec-1', $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['evidence_refs']);
    }

    public function test_capability_delta_partial_before_only_still_blocks(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']],
            'verification' => ['server_side_green' => true, 'ref' => 'verif-1'],
            'capability_delta' => ['before' => 'feature absent', 'after' => ''], // after empty
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['status']);
    }

    // ── regression_signals gate ───────────────────────────────────────────────

    public function test_regression_signal_blocks_confirmed_class_and_preserves_refs(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'simplification', 'ref' => 'rec-simpl']],
            'verification' => ['server_side_green' => true, 'ref' => 'verif-2'],
            'regression_signals' => [['kind' => 'test_failure', 'ref' => 'reg-1']],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $simplFact = $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION];
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $simplFact['status']);
        $this->assertSame('regression_signal_present', $simplFact['reason']);
        $this->assertContains('rec-simpl', $simplFact['evidence_refs']);
        $this->assertContains('reg-1', $simplFact['evidence_refs']);
    }

    public function test_regression_signal_does_not_change_already_unknown_or_blocked_classes(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']], // verification absent → blocked
            'regression_signals' => [['kind' => 'test_failure', 'ref' => 'reg-1']],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        // failure_removal, autonomy_lift, reuse are unknown (no receipt); must NOT change to regression_signal_present
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_UNKNOWN, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_FAILURE_REMOVAL]['status']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_UNKNOWN, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status']);
    }

    public function test_value_facts_are_sorted_deterministically_by_class(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([]);
        $classes = array_column($r['value_facts'], 'class');
        $sorted = $classes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $classes, 'value_facts must be sorted alphabetically by class');
    }

    // ── AC3: risk_reduction and quality_improvement need explicit receipt + supporting ref ──

    public function test_risk_reduction_confirmed_with_receipt_and_verification(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'risk_reduced', 'ref' => 'rec-risk']],
            'verification' => ['server_side_green' => true, 'ref' => 'v-risk'],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_RISK_REDUCTION]['status']);
    }

    public function test_risk_reduction_unknown_without_receipt(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_UNKNOWN, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_RISK_REDUCTION]['status']);
    }

    public function test_quality_improvement_confirmed_with_receipt_and_passing_regression_gate(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'quality_improved', 'ref' => 'rec-qual']],
            'gates' => [['kind' => 'regression_test', 'ref' => 'gate-qual', 'passed' => true]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_QUALITY_IMPROVEMENT]['status']);
    }

    public function test_quality_improvement_blocked_without_passing_gate(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'quality_improved', 'ref' => 'rec-qual']],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_QUALITY_IMPROVEMENT]['status']);
    }

    // ── AC2: partial impact via evidence_strength=weak ──────────────────────────

    public function test_weak_evidence_strength_yields_partial_not_confirmed(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'reuse_existing', 'ref' => 'rec-reuse', 'evidence_strength' => 'weak']],
            'gates' => [['kind' => 'code_index', 'ref' => 'gate-2', 'passed' => true]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_PARTIAL, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status']);
    }

    public function test_default_evidence_strength_is_strong_and_yields_confirmed(): void
    {
        // Backward compatibility: omitting evidence_strength entirely must reproduce confirmed exactly.
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'reuse_existing', 'ref' => 'rec-reuse']],
            'gates' => [['kind' => 'code_index', 'ref' => 'gate-2', 'passed' => true]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status']);
    }

    // ── AC4: discounts cosmetic wrappers, count-only commits and proofless green tests ──

    public function test_discount_signal_downgrades_confirmed_class_to_proxy(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'simplification', 'ref' => 'rec-simpl']],
            'verification' => ['server_side_green' => true, 'ref' => 'v-1'],
            'discount_signals' => [['kind' => 'cosmetic_wrapper', 'ref' => 'discount-1', 'applies_to_class' => AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $fact = $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION];
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_PROXY, $fact['status']);
        $this->assertContains('rec-simpl', $fact['evidence_refs']);
        $this->assertContains('discount-1', $fact['evidence_refs']);
    }

    public function test_global_discount_signal_without_applies_to_class_discounts_all_confirmed(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [
                ['kind' => 'simplification', 'ref' => 'rec-simpl'],
                ['kind' => 'reuse_existing', 'ref' => 'rec-reuse'],
            ],
            'verification' => ['server_side_green' => true, 'ref' => 'v-1'],
            'gates' => [['kind' => 'code_index', 'ref' => 'gate-2', 'passed' => true]],
            'discount_signals' => [['kind' => 'count_only_commit']],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_PROXY, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION]['status']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_PROXY, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status']);
    }

    public function test_discount_signal_never_upgrades_unknown_or_blocked_to_proxy(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']], // verification absent → blocked
            'discount_signals' => [['kind' => 'proofless_green_test']],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_CAPABILITY_LIFT]['status']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_UNKNOWN, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_REUSE]['status']);
    }

    public function test_no_discount_signals_leaves_confirmed_classes_untouched(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'simplification', 'ref' => 'rec-simpl']],
            'verification' => ['server_side_green' => true, 'ref' => 'v-1'],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_SIMPLIFICATION]['status']);
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,array<string,mixed>>
     */
    private function indexByClass(array $facts): array
    {
        $out = [];
        foreach ($facts as $f) {
            $out[$f['class']] = $f;
        }

        return $out;
    }
}

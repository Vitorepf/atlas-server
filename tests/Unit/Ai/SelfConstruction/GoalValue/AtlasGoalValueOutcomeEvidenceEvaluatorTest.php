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
    public function test_capability_lift_confirmed_with_receipt_and_passing_verification(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'new_capability', 'ref' => 'rec-1']],
            'verification' => ['server_side_green' => true, 'ref' => 'verif-1'],
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

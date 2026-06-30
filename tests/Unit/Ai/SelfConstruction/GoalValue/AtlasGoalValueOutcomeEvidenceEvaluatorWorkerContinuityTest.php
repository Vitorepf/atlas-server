<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueOutcomeEvidenceEvaluator;
use PHPUnit\Framework\TestCase;

final class AtlasGoalValueOutcomeEvidenceEvaluatorWorkerContinuityTest extends TestCase
{
    /**
     * @param  list<array<string,mixed>>  $valueFacts
     * @return array<string,array<string,mixed>>
     */
    private function indexByClass(array $valueFacts): array
    {
        $out = [];
        foreach ($valueFacts as $f) {
            $out[$f['class']] = $f;
        }

        return $out;
    }

    public function test_worker_continuity_confirmed_with_receipt_gate_and_claimable_improvement(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'worker_continuity', 'ref' => 'rec-wc']],
            'gates' => [['kind' => 'queue_continuity', 'ref' => 'gate-qc', 'passed' => true]],
            'queue_continuity_delta' => [
                'claimable_per_active_worker_before' => 0.4,
                'claimable_per_active_worker_after' => 1.2,
            ],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
        $this->assertContains('rec-wc', $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['evidence_refs']);
    }

    public function test_worker_continuity_confirmed_when_no_claimable_task_incidents_dropped(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'worker_continuity', 'ref' => 'rec-wc']],
            'gates' => [['kind' => 'queue_continuity', 'ref' => 'gate-qc', 'passed' => true]],
            'queue_continuity_delta' => [
                'no_claimable_task_incidents_before' => 12,
                'no_claimable_task_incidents_after' => 2,
            ],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
    }

    public function test_missing_before_after_queue_evidence_blocks_not_confirms(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'worker_continuity', 'ref' => 'rec-wc']],
            'gates' => [['kind' => 'queue_continuity', 'ref' => 'gate-qc', 'passed' => true]],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertNotSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_CONFIRMED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
    }

    public function test_partial_before_only_queue_evidence_still_blocks(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'worker_continuity', 'ref' => 'rec-wc']],
            'gates' => [['kind' => 'queue_continuity', 'ref' => 'gate-qc', 'passed' => true]],
            'queue_continuity_delta' => ['claimable_per_active_worker_before' => 0.4],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
    }

    public function test_no_regression_in_value_does_not_confirm_worker_continuity(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'worker_continuity', 'ref' => 'rec-wc']],
            'gates' => [['kind' => 'queue_continuity', 'ref' => 'gate-qc', 'passed' => true]],
            'queue_continuity_delta' => [
                'claimable_per_active_worker_before' => 1.2,
                'claimable_per_active_worker_after' => 0.4,
            ],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
    }

    public function test_missing_receipt_yields_unknown_not_confirmed(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'gates' => [['kind' => 'queue_continuity', 'ref' => 'gate-qc', 'passed' => true]],
            'queue_continuity_delta' => [
                'claimable_per_active_worker_before' => 0.4,
                'claimable_per_active_worker_after' => 1.2,
            ],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_UNKNOWN, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
    }

    public function test_missing_gate_yields_blocked(): void
    {
        $r = (new AtlasGoalValueOutcomeEvidenceEvaluator)->evaluate([
            'receipts' => [['kind' => 'worker_continuity', 'ref' => 'rec-wc']],
            'queue_continuity_delta' => [
                'claimable_per_active_worker_before' => 0.4,
                'claimable_per_active_worker_after' => 1.2,
            ],
        ]);
        $byClass = $this->indexByClass($r['value_facts']);

        $this->assertSame(AtlasGoalValueOutcomeEvidenceEvaluator::STATUS_BLOCKED, $byClass[AtlasGoalValueOutcomeEvidenceEvaluator::CLASS_WORKER_CONTINUITY]['status']);
    }
}

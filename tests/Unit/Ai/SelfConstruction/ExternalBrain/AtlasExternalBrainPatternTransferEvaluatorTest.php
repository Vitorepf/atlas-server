<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPatternTransferEvaluator;
use Tests\TestCase;

final class AtlasExternalBrainPatternTransferEvaluatorTest extends TestCase
{
    private function evaluator(): AtlasExternalBrainPatternTransferEvaluator
    {
        return new AtlasExternalBrainPatternTransferEvaluator();
    }

    private function outcome(
        string $class,
        float  $positiveRatio = 0.80,
        float  $giveBackRate  = 0.10,
        float  $duplicateRate = 0.05,
        float  $weakAcceptanceRate = 0.10,
        int    $evidenceCount = 5,
    ): array {
        return [
            'task_class'          => $class,
            'positive_ratio'      => $positiveRatio,
            'give_back_rate'      => $giveBackRate,
            'duplicate_rate'      => $duplicateRate,
            'weak_acceptance_rate' => $weakAcceptanceRate,
            'evidence_count'      => $evidenceCount,
        ];
    }

    private function pattern(string $id, array $outcomes, array $targets = ['class-b']): array
    {
        return [
            'pattern_id'          => $id,
            'description'         => "Pattern {$id}",
            'source_task_classes' => ['class-a'],
            'target_task_classes' => $targets,
            'cross_class_outcomes' => $outcomes,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->evaluator()->evaluate([]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::SCHEMA, $result['schema']);
    }

    public function test_output_has_schema_and_results(): void
    {
        $result = $this->evaluator()->evaluate([]);

        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('results', $result);
    }

    public function test_each_result_has_required_keys(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b')])],
        ]);

        foreach ($result['results'] as $r) {
            foreach (['pattern_id', 'transfer_decision', 'source_task_classes',
                      'target_task_classes', 'evidence_counts', 'injection_rule'] as $k) {
                $this->assertArrayHasKey($k, $r);
            }
        }
    }

    public function test_empty_patterns_yields_empty_results(): void
    {
        $result = $this->evaluator()->evaluate(['patterns' => []]);

        $this->assertSame([], $result['results']);
    }

    // ── transferable ──────────────────────────────────────────────────────────

    public function test_transferable_when_all_outcomes_above_positive_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_TRANSFERABLE, $result['results'][0]['transfer_decision']);
    }

    public function test_transferable_injection_rule(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80)])],
        ]);

        $this->assertStringContainsString('inject into all target_task_classes', $result['results'][0]['injection_rule']);
    }

    // ── retire ────────────────────────────────────────────────────────────────

    public function test_retire_when_give_back_rate_above_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.35)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    public function test_retire_when_duplicate_rate_above_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.25)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    public function test_retire_when_weak_acceptance_above_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.10, 0.10, 0.45)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    public function test_retire_injection_rule(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.35)])],
        ]);

        $this->assertStringContainsString('do not inject', $result['results'][0]['injection_rule']);
    }

    public function test_retire_takes_priority_over_other_decisions(): void
    {
        // Give back in one class but low evidence in another — retire must win.
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', 0.80, 0.35, 0.05, 0.05, 1), // harmful + low evidence
            ])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    // ── needs_more_evidence ───────────────────────────────────────────────────

    public function test_needs_more_evidence_when_evidence_count_below_minimum(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.05, 0.10, 2)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $result['results'][0]['transfer_decision']);
    }

    public function test_needs_more_evidence_injection_rule_mentions_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.05, 0.10, 2)])],
        ]);

        $this->assertStringContainsString('withhold', $result['results'][0]['injection_rule']);
    }

    public function test_needs_more_evidence_when_no_outcomes(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $result['results'][0]['transfer_decision']);
    }

    // ── local_only ────────────────────────────────────────────────────────────

    public function test_local_only_when_one_target_class_below_positive_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', 0.80), // good
                $this->outcome('class-c', 0.50), // below 0.70 threshold
            ], ['class-b', 'class-c'])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_LOCAL_ONLY, $result['results'][0]['transfer_decision']);
    }

    public function test_local_only_injection_rule(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', 0.80),
                $this->outcome('class-c', 0.50),
            ], ['class-b', 'class-c'])],
        ]);

        $this->assertStringContainsString('inject only into source_task_classes', $result['results'][0]['injection_rule']);
    }

    // ── evidence_counts ───────────────────────────────────────────────────────

    public function test_evidence_counts_indexed_by_task_class(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', evidenceCount: 7),
                $this->outcome('class-c', evidenceCount: 4),
            ], ['class-b', 'class-c'])],
        ]);

        $counts = $result['results'][0]['evidence_counts'];
        $this->assertSame(7, $counts['class-b']);
        $this->assertSame(4, $counts['class-c']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_min_evidence_count_respected(): void
    {
        // Default min is 3; override to 10 — evidence_count=5 should now be insufficient.
        $result = $this->evaluator()->evaluate([
            'patterns'   => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.05, 0.05, 5)])],
            'thresholds' => ['min_evidence_count' => 10],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $result['results'][0]['transfer_decision']);
    }

    public function test_custom_give_back_threshold_respected(): void
    {
        // Raise give_back_threshold to 0.50 — rate of 0.35 should no longer trigger retire.
        $result = $this->evaluator()->evaluate([
            'patterns'   => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.35)])],
            'thresholds' => ['give_back_threshold' => 0.50],
        ]);

        $this->assertNotSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b')])],
        ];

        $this->assertSame($this->evaluator()->evaluate($input), $this->evaluator()->evaluate($input));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Compounding;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingOutcomeProjection;
use Tests\TestCase;

final class AtlasSelfConstructionCompoundingOutcomeProjectionTest extends TestCase
{
    public function test_passed_outcome_is_projected(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project([
            ['organ' => 'cortex', 'task_class' => 'recall', 'cycle_id' => 'c-1', 'evidence_hash' => 'h1', 'outcome' => 'success'],
        ]);

        $this->assertCount(1, $verdict['groups']);
        $this->assertSame(AtlasSelfConstructionCompoundingOutcomeProjection::OUTCOME_PASSED, $verdict['groups'][0]['outcomes'][0]['outcome']);
    }

    public function test_failed_outcome_is_projected(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project([
            ['organ' => 'verification_court', 'task_class' => 'gate', 'cycle_id' => 'c-2', 'evidence_hash' => 'h2', 'outcome' => 'regression'],
        ]);

        $this->assertSame(AtlasSelfConstructionCompoundingOutcomeProjection::OUTCOME_FAILED, $verdict['groups'][0]['outcomes'][0]['outcome']);
    }

    public function test_learning_required_outcome_is_projected_for_amber_and_unknown(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project([
            ['organ' => 'learning_transfer', 'task_class' => 'lesson', 'cycle_id' => 'c-3', 'evidence_hash' => 'h3', 'outcome' => 'amber'],
            ['organ' => 'learning_transfer', 'task_class' => 'lesson', 'cycle_id' => 'c-4', 'evidence_hash' => 'h4', 'outcome' => 'unknown'],
        ]);

        foreach ($verdict['groups'] as $g) {
            $this->assertSame(AtlasSelfConstructionCompoundingOutcomeProjection::OUTCOME_LEARNING, $g['outcomes'][0]['outcome']);
        }
    }

    public function test_records_with_same_key_are_grouped_together(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project([
            ['organ' => 'cortex', 'task_class' => 'recall', 'cycle_id' => 'c-1', 'evidence_hash' => 'h1', 'outcome' => 'success'],
            ['organ' => 'cortex', 'task_class' => 'recall', 'cycle_id' => 'c-1', 'evidence_hash' => 'h1', 'outcome' => 'amber'],
        ]);

        $this->assertCount(1, $verdict['groups'], 'same organ/task_class/cycle_id/evidence_hash MUST coalesce into one group');
        $this->assertCount(2, $verdict['groups'][0]['outcomes']);
    }

    public function test_raw_fact_is_preserved_for_audit(): void
    {
        $rec = ['organ' => 'maestro', 'task_class' => 'plan', 'cycle_id' => 'c-5', 'evidence_hash' => 'h5', 'outcome' => 'passed', 'arbitrary_field' => 'preserve_me'];
        $verdict = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project([$rec]);

        $this->assertSame($rec, $verdict['groups'][0]['outcomes'][0]['raw_fact'], 'raw_fact must be byte-equal to input');
    }

    private function rec(string $organ, string $outcome, int $n = 1): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['organ' => $organ, 'task_class' => 't', 'cycle_id' => "c-{$i}", 'evidence_hash' => "h{$i}", 'outcome' => $outcome];
        }
        return $out;
    }

    public function test_high_pass_rate_yields_compounding_trend(): void
    {
        $records = array_merge($this->rec('cortex', 'success', 4), $this->rec('maestro', 'passed', 4));
        $v = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($records);

        $this->assertSame('compounding', $v['summary']['trend']);
        $this->assertGreaterThanOrEqual(0.75, $v['summary']['rates']['passed_rate']);
    }

    public function test_mixed_results_yield_flat_volume_trend(): void
    {
        $records = array_merge($this->rec('a', 'success', 5), $this->rec('b', 'amber', 4), $this->rec('c', 'failed', 1));
        $v = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($records);

        $this->assertSame('flat_volume', $v['summary']['trend']);
    }

    public function test_high_failure_rate_yields_quality_decay_trend(): void
    {
        $records = array_merge($this->rec('a', 'regression', 4), $this->rec('b', 'success', 2));
        $v = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($records);

        $this->assertSame('quality_decay', $v['summary']['trend']);
        $this->assertGreaterThanOrEqual(0.3, $v['summary']['rates']['failed_rate']);
    }

    public function test_give_back_dominant_yields_give_back_drag_trend(): void
    {
        $records = array_merge($this->rec('a', 'give_back', 4), $this->rec('b', 'success', 2));
        $v = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($records);

        $this->assertSame('give_back_drag', $v['summary']['trend']);
        $this->assertGreaterThanOrEqual(0.3, $v['summary']['rates']['give_back_rate']);
    }

    public function test_confidence_bounds_low_medium_high_by_sample_size(): void
    {
        $low    = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($this->rec('a', 'success', 1));
        $medium = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($this->rec('a', 'success', 3));
        $high   = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project($this->rec('a', 'success', 10));

        $this->assertSame('low',    $low['summary']['confidence']);
        $this->assertSame('medium', $medium['summary']['confidence']);
        $this->assertSame('high',   $high['summary']['confidence']);
    }

    public function test_groups_are_sorted_deterministically_by_key(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingOutcomeProjection)->project([
            ['organ' => 'c', 'task_class' => 't', 'cycle_id' => 'x', 'evidence_hash' => 'z'],
            ['organ' => 'a', 'task_class' => 't', 'cycle_id' => 'x', 'evidence_hash' => 'z'],
            ['organ' => 'b', 'task_class' => 't', 'cycle_id' => 'x', 'evidence_hash' => 'z'],
        ]);

        $this->assertSame(['a|t|x|z', 'b|t|x|z', 'c|t|x|z'], array_column($verdict['groups'], 'key'));
    }
}

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

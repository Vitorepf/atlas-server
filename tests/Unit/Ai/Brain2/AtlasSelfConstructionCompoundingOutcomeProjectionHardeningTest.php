<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingOutcomeProjection;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionCompoundingOutcomeProjection::project() cannot collide
 * when organ or task_class contain the old pipe delimiter.
 */
final class AtlasSelfConstructionCompoundingOutcomeProjectionHardeningTest extends TestCase
{
    public function test_pipe_in_organ_does_not_collide(): void
    {
        $projection = new AtlasSelfConstructionCompoundingOutcomeProjection();

        $result = $projection->project([
            [
                'organ' => 'a|b',
                'task_class' => 'c',
                'cycle_id' => 'cycle-1',
                'evidence_hash' => 'h1',
                'outcome' => 'passed',
            ],
            [
                'organ' => 'a',
                'task_class' => 'b|c',
                'cycle_id' => 'cycle-1',
                'evidence_hash' => 'h1',
                'outcome' => 'failed',
            ],
        ]);

        // Two distinct groups — not merged
        $this->assertCount(2, $result['groups']);
    }

    public function test_identical_tuples_group_together(): void
    {
        $projection = new AtlasSelfConstructionCompoundingOutcomeProjection();

        $result = $projection->project([
            [
                'organ' => 'brain',
                'task_class' => 'harden',
                'cycle_id' => 'cycle-1',
                'evidence_hash' => 'h1',
                'outcome' => 'passed',
            ],
            [
                'organ' => 'brain',
                'task_class' => 'harden',
                'cycle_id' => 'cycle-1',
                'evidence_hash' => 'h1',
                'outcome' => 'failed',
            ],
        ]);

        // One group with two outcomes
        $this->assertCount(1, $result['groups']);
        $this->assertCount(2, $result['groups'][0]['outcomes']);
    }

    public function test_different_organ_produces_different_group(): void
    {
        $projection = new AtlasSelfConstructionCompoundingOutcomeProjection();

        $result = $projection->project([
            [
                'organ' => 'brain',
                'task_class' => 'harden',
                'cycle_id' => 'cycle-1',
                'evidence_hash' => 'h1',
                'outcome' => 'passed',
            ],
            [
                'organ' => 'maestro',
                'task_class' => 'harden',
                'cycle_id' => 'cycle-1',
                'evidence_hash' => 'h1',
                'outcome' => 'passed',
            ],
        ]);

        $this->assertCount(2, $result['groups']);
    }
}

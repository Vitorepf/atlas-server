<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Compounding;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionGiveBackLessonConsolidator;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionGiveBackLessonConsolidatorTest extends TestCase
{
    private AtlasSelfConstructionGiveBackLessonConsolidator $consolidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consolidator = new AtlasSelfConstructionGiveBackLessonConsolidator;
    }

    public function test_three_scope_mismatch_lessons_produce_repeat_count_3(): void
    {
        // AC4: three lessons with 'give_back_due_to_scope_mismatch' → repeat_count=3.
        // This assertion FAILS against a no-op that returns [].
        $input = [
            'lessons' => [
                'scope_quality' => [
                    ['task_id' => 't1', 'lesson' => 'give_back_due_to_scope_mismatch', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't2', 'lesson' => 'give_back_due_to_scope_mismatch', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't3', 'lesson' => 'give_back_due_to_scope_mismatch', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w2'],
                ],
            ],
        ];

        $result = $this->consolidator->consolidate($input);

        $this->assertNotEmpty($result, 'no-op mock returns [] and fails here');
        $this->assertContains(['class' => 'give_back_due_to_scope_mismatch', 'repeat_count' => 3], $result);
    }

    public function test_single_occurrence_code_has_repeat_count_1(): void
    {
        $input = [
            'lessons' => [
                'routing_quality' => [
                    ['task_id' => 't1', 'lesson' => 'give_back_unclassified_reason', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                ],
            ],
        ];

        $result = $this->consolidator->consolidate($input);

        $this->assertContains(['class' => 'give_back_unclassified_reason', 'repeat_count' => 1], $result);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->consolidator->consolidate([]));
        $this->assertSame([], $this->consolidator->consolidate(['lessons' => []]));
    }

    public function test_malformed_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->consolidator->consolidate(['lessons' => 'not_an_array']));
        $this->assertSame([], $this->consolidator->consolidate(['lessons' => ['invalid' => 'data']]));
    }

    public function test_ordering_is_repeat_count_desc_then_class_asc(): void
    {
        $input = [
            'lessons' => [
                'spec_quality' => [
                    ['task_id' => 't1', 'lesson' => 'alpha', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't3', 'lesson' => 'beta', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't4', 'lesson' => 'beta', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't5', 'lesson' => 'gamma', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't6', 'lesson' => 'gamma', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't7', 'lesson' => 'gamma', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                ],
            ],
        ];

        $result = $this->consolidator->consolidate($input);

        // gamma (3) > beta (2) > alpha (1)
        $this->assertSame('gamma', $result[0]['class']);
        $this->assertSame(3, $result[0]['repeat_count']);
        $this->assertSame('beta', $result[1]['class']);
        $this->assertSame('alpha', $result[2]['class']);
    }

    public function test_repeat_count_desc_tiebroken_by_class_asc(): void
    {
        $input = [
            'lessons' => [
                'spec_quality' => [
                    ['task_id' => 't1', 'lesson' => 'bbb', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                    ['task_id' => 't2', 'lesson' => 'aaa', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                ],
            ],
        ];

        $result = $this->consolidator->consolidate($input);

        // Both have repeat_count=1, so ties sorted by class asc.
        $this->assertSame('aaa', $result[0]['class']);
        $this->assertSame('bbb', $result[1]['class']);
    }

    public function test_mixed_categories_are_all_counted_together(): void
    {
        // Same lesson code appearing in different categories.
        $input = [
            'lessons' => [
                'scope_quality' => [
                    ['task_id' => 't1', 'lesson' => 'give_back_due_to_scope_mismatch', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                ],
                'spec_quality' => [
                    ['task_id' => 't2', 'lesson' => 'give_back_due_to_scope_mismatch', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w1'],
                ],
                'implementation_risk' => [
                    ['task_id' => 't3', 'lesson' => 'give_back_due_to_scope_mismatch', 'low_confidence' => false, 'task_family' => 'fam', 'worker_id' => 'w2'],
                ],
            ],
        ];

        $result = $this->consolidator->consolidate($input);

        $this->assertContains(['class' => 'give_back_due_to_scope_mismatch', 'repeat_count' => 3], $result);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostImplementationLessonExtractor;
use Tests\TestCase;

final class AtlasExternalBrainPostImplementationLessonExtractorTest extends TestCase
{
    private AtlasExternalBrainPostImplementationLessonExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AtlasExternalBrainPostImplementationLessonExtractor;
    }

    private function outcome(string $taskId, array $overrides = []): array
    {
        return array_merge([
            'task_id' => $taskId,
            'result' => 'success',
            'commit_sha' => str_repeat('a', 40),
            'tests_run' => true,
            'give_back_reason' => '',
            'changed_files' => ['app/Foo.php'],
            'worker_id' => 'worker-1',
            'task_family' => 'external_brain',
            'elapsed_minutes' => 10,
        ], $overrides);
    }

    public function test_verified_success_yields_proof_quality_lesson_not_low_confidence(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t1')]]);

        $this->assertSame(AtlasExternalBrainPostImplementationLessonExtractor::SCHEMA, $result['schema']);
        $this->assertCount(1, $result['lessons']['proof_quality']);
        $lesson = $result['lessons']['proof_quality'][0];
        $this->assertSame('verified_success_with_commit_and_tests', $lesson['lesson']);
        $this->assertFalse($lesson['low_confidence']);
    }

    public function test_success_without_commit_sha_is_discounted_low_confidence(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t2', ['commit_sha' => ''])]]);

        $lesson = $result['lessons']['proof_quality'][0];
        $this->assertSame('self_reported_success_without_evidence', $lesson['lesson']);
        $this->assertTrue($lesson['low_confidence']);
    }

    public function test_success_without_tests_run_is_discounted_low_confidence(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t3', ['tests_run' => false])]]);

        $lesson = $result['lessons']['proof_quality'][0];
        $this->assertSame('self_reported_success_without_evidence', $lesson['lesson']);
        $this->assertTrue($lesson['low_confidence']);
    }

    public function test_give_back_with_scope_reason_groups_into_scope_quality(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('t4', ['result' => 'give_back', 'give_back_reason' => 'required implementation outside allowed scope']),
        ]]);

        $this->assertCount(1, $result['lessons']['scope_quality']);
        $this->assertSame('give_back_due_to_scope_mismatch', $result['lessons']['scope_quality'][0]['lesson']);
    }

    public function test_give_back_with_spec_reason_groups_into_spec_quality(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('t5', ['result' => 'give_back', 'give_back_reason' => 'contradictory acceptance criteria']),
        ]]);

        $this->assertCount(1, $result['lessons']['spec_quality']);
        $this->assertSame('give_back_due_to_unclear_spec', $result['lessons']['spec_quality'][0]['lesson']);
    }

    public function test_give_back_with_unclassified_reason_groups_into_routing_quality(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('t6', ['result' => 'give_back', 'give_back_reason' => 'duplicate of existing capability']),
        ]]);

        $this->assertCount(1, $result['lessons']['routing_quality']);
        $this->assertSame('give_back_unclassified_reason', $result['lessons']['routing_quality'][0]['lesson']);
    }

    public function test_repair_result_yields_implementation_risk_lesson(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t7', ['result' => 'repair'])]]);

        $this->assertContains(
            'required_repair_after_initial_attempt',
            array_column($result['lessons']['implementation_risk'], 'lesson'),
        );
    }

    public function test_failed_result_yields_implementation_risk_lesson(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t8', ['result' => 'failed'])]]);

        $this->assertContains(
            'attempt_failed',
            array_column($result['lessons']['implementation_risk'], 'lesson'),
        );
    }

    public function test_long_elapsed_time_yields_implementation_risk_lesson(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t9', ['elapsed_minutes' => 90])]]);

        $this->assertContains(
            'long_elapsed_time_review_task_sizing',
            array_column($result['lessons']['implementation_risk'], 'lesson'),
        );
    }

    public function test_large_changed_file_count_yields_scope_quality_lesson(): void
    {
        $manyFiles = array_map(static fn (int $i): string => "app/File{$i}.php", range(1, 15));
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t10', ['changed_files' => $manyFiles])]]);

        $this->assertContains(
            'large_changed_file_count_review_scope',
            array_column($result['lessons']['scope_quality'], 'lesson'),
        );
    }

    public function test_lesson_count_aggregates_across_categories(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('t11', ['elapsed_minutes' => 90]),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
    }

    public function test_empty_outcomes_returns_empty_lesson_groups(): void
    {
        $result = $this->extractor->extract(['outcomes' => []]);

        foreach (AtlasExternalBrainPostImplementationLessonExtractor::CATEGORIES as $category) {
            $this->assertSame([], $result['lessons'][$category]);
        }
        $this->assertSame(0, $result['lesson_count']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostImplementationLessonExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainPostImplementationLessonExtractor produces family-level memory facts,
 * worker-level routing hints, and low-confidence flags from post-implementation outcomes — so the
 * next brain batch learns from muscle commits without trusting a bare self-report.
 */
final class AtlasExternalBrainPostImplementationLessonExtractorTest extends TestCase
{
    private AtlasExternalBrainPostImplementationLessonExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AtlasExternalBrainPostImplementationLessonExtractor;
    }

    /** @param array<string,mixed> $overrides */
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

    public function test_verified_success_with_commit_and_tests_yields_proof_quality_without_low_confidence(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t1')]]);

        $lesson = $result['lessons']['proof_quality'][0];
        $this->assertSame('verified_success_with_commit_and_tests', $lesson['lesson']);
        $this->assertFalse($lesson['low_confidence']);
    }

    public function test_self_reported_success_without_commit_is_low_confidence_and_not_counted_as_verified(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t2', ['commit_sha' => ''])]]);

        $lesson = $result['lessons']['proof_quality'][0];
        $this->assertSame('self_reported_success_without_evidence', $lesson['lesson']);
        $this->assertTrue($lesson['low_confidence']);
        $this->assertNotSame('verified_success_with_commit_and_tests', $lesson['lesson']);
    }

    public function test_self_reported_success_without_tests_run_is_low_confidence(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t3', ['tests_run' => false])]]);

        $lesson = $result['lessons']['proof_quality'][0];
        $this->assertSame('self_reported_success_without_evidence', $lesson['lesson']);
        $this->assertTrue($lesson['low_confidence']);
    }

    // ── AC4: give_back / failed / repair / long_elapsed / large_changed_files each categorized,
    // with task_family and worker_id preserved on every lesson ──

    public function test_give_back_scope_reason_yields_scope_quality_lesson_with_family_and_worker_preserved(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t4', [
            'result' => 'give_back', 'give_back_reason' => 'scope mismatch', 'worker_id' => 'worker-9', 'task_family' => 'lease_cert',
        ])]]);

        $lesson = $result['lessons']['scope_quality'][0];
        $this->assertSame('give_back_due_to_scope_mismatch', $lesson['lesson']);
        $this->assertSame('worker-9', $lesson['worker_id']);
        $this->assertSame('lease_cert', $lesson['task_family']);
    }

    public function test_failed_outcome_yields_implementation_risk_lesson_with_family_and_worker_preserved(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t5', [
            'result' => 'failed', 'worker_id' => 'worker-7', 'task_family' => 'auto_replenish',
        ])]]);

        $lesson = $result['lessons']['implementation_risk'][0];
        $this->assertSame('attempt_failed', $lesson['lesson']);
        $this->assertSame('worker-7', $lesson['worker_id']);
        $this->assertSame('auto_replenish', $lesson['task_family']);
    }

    public function test_repair_outcome_yields_implementation_risk_lesson_with_family_and_worker_preserved(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t6', [
            'result' => 'repair', 'worker_id' => 'worker-3', 'task_family' => 'stop_go_bridge',
        ])]]);

        $lesson = $result['lessons']['implementation_risk'][0];
        $this->assertSame('required_repair_after_initial_attempt', $lesson['lesson']);
        $this->assertSame('worker-3', $lesson['worker_id']);
        $this->assertSame('stop_go_bridge', $lesson['task_family']);
    }

    public function test_long_elapsed_yields_distinct_implementation_risk_lesson_with_family_and_worker_preserved(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t7', [
            'elapsed_minutes' => 90, 'worker_id' => 'worker-2', 'task_family' => 'proxy_leak',
        ])]]);

        $codes = array_column($result['lessons']['implementation_risk'], 'lesson');
        $this->assertContains('long_elapsed_time_review_task_sizing', $codes);
        $entry = $result['lessons']['implementation_risk'][array_search('long_elapsed_time_review_task_sizing', $codes, true)];
        $this->assertSame('worker-2', $entry['worker_id']);
        $this->assertSame('proxy_leak', $entry['task_family']);
    }

    public function test_large_changed_files_yields_distinct_scope_quality_lesson_with_family_and_worker_preserved(): void
    {
        $manyFiles = array_map(static fn (int $i): string => "app/File{$i}.php", range(1, 15));
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('t8', [
            'changed_files' => $manyFiles, 'worker_id' => 'worker-4', 'task_family' => 'operator_independence',
        ])]]);

        $codes = array_column($result['lessons']['scope_quality'], 'lesson');
        $this->assertContains('large_changed_file_count_review_scope', $codes);
        $entry = $result['lessons']['scope_quality'][array_search('large_changed_file_count_review_scope', $codes, true)];
        $this->assertSame('worker-4', $entry['worker_id']);
        $this->assertSame('operator_independence', $entry['task_family']);
    }

    // ── family-level memory facts ──────────────────────────────────────────────

    public function test_family_memory_facts_aggregate_lesson_and_low_confidence_counts_per_family(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('a1', ['task_family' => 'lease_cert']),
            $this->outcome('a2', ['task_family' => 'lease_cert', 'commit_sha' => '']),
            $this->outcome('a3', ['task_family' => 'other_family']),
        ]]);

        $byFamily = [];
        foreach ($result['family_memory_facts'] as $fact) {
            $byFamily[$fact['task_family']] = $fact;
        }

        $this->assertSame(2, $byFamily['lease_cert']['outcomes_seen']);
        $this->assertSame(2, $byFamily['lease_cert']['lesson_count']);
        $this->assertSame(1, $byFamily['lease_cert']['low_confidence_count'], 'only the commit-less outcome is low confidence');
        $this->assertSame(1, $byFamily['other_family']['outcomes_seen']);
    }

    public function test_outcomes_without_task_family_are_excluded_from_family_memory_facts(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('b1', ['task_family' => ''])]]);

        $this->assertSame([], $result['family_memory_facts']);
    }

    // ── worker-level routing hints ───────────────────────────────────────────────

    public function test_worker_routing_hints_aggregate_result_counts_per_worker(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('c1', ['worker_id' => 'worker-5', 'result' => 'success']),
            $this->outcome('c2', ['worker_id' => 'worker-5', 'result' => 'give_back']),
            $this->outcome('c3', ['worker_id' => 'worker-5', 'result' => 'give_back']),
            $this->outcome('c4', ['worker_id' => 'worker-6', 'result' => 'failed']),
        ]]);

        $byWorker = [];
        foreach ($result['worker_routing_hints'] as $hint) {
            $byWorker[$hint['worker_id']] = $hint;
        }

        $this->assertSame(3, $byWorker['worker-5']['total_outcomes']);
        $this->assertSame(1, $byWorker['worker-5']['success_count']);
        $this->assertSame(2, $byWorker['worker-5']['give_back_count']);
        $this->assertSame(1, $byWorker['worker-6']['failed_count']);
    }

    public function test_outcomes_without_worker_id_are_excluded_from_worker_routing_hints(): void
    {
        $result = $this->extractor->extract(['outcomes' => [$this->outcome('d1', ['worker_id' => ''])]]);

        $this->assertSame([], $result['worker_routing_hints']);
    }

    public function test_low_confidence_only_fires_on_the_self_reported_success_rule(): void
    {
        $result = $this->extractor->extract(['outcomes' => [
            $this->outcome('e1', ['result' => 'give_back', 'give_back_reason' => 'unrelated']),
            $this->outcome('e2', ['result' => 'failed']),
        ]]);

        foreach ($result['lessons'] as $category => $entries) {
            foreach ($entries as $entry) {
                $this->assertFalse($entry['low_confidence'], "{$category}/{$entry['lesson']} must not be low_confidence");
            }
        }
    }

    public function test_extraction_is_deterministic_for_identical_input(): void
    {
        $input = ['outcomes' => [
            $this->outcome('f1'),
            $this->outcome('f2', ['result' => 'give_back', 'give_back_reason' => 'scope drift']),
        ]];

        $run1 = $this->extractor->extract($input);
        $run2 = $this->extractor->extract($input);
        $this->assertSame($run1, $run2);
    }
}

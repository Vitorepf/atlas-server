<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Extracts a compact lesson from each green commit, give_back, repair, or
 * failed attempt so future task specs and routing decisions can improve.
 * Pure: it only reads supplied outcome facts and returns grouped lessons —
 * it never writes memory, never calls a provider, never mutates the queue.
 *
 * Each outcome can produce zero or more lessons, grouped into five
 * categories: spec_quality, routing_quality, proof_quality, scope_quality,
 * implementation_risk.
 *
 * RULES (per outcome, independent — more than one may fire):
 *   result=success AND (commit_sha empty OR tests_run=false)
 *     -> proof_quality: self_reported_success_without_evidence (low_confidence=true)
 *   result=success AND commit_sha non-empty AND tests_run=true
 *     -> proof_quality: verified_success_with_commit_and_tests
 *   result=give_back AND give_back_reason contains "scope"
 *     -> scope_quality: give_back_due_to_scope_mismatch
 *   result=give_back AND give_back_reason contains "spec"/"objective"/"acceptance"
 *     -> spec_quality: give_back_due_to_unclear_spec
 *   result=give_back (no scope/spec signal in reason)
 *     -> routing_quality: give_back_unclassified_reason
 *   result=repair
 *     -> implementation_risk: required_repair_after_initial_attempt
 *   result=failed
 *     -> implementation_risk: attempt_failed
 *   elapsed_minutes >= 60
 *     -> implementation_risk: long_elapsed_time_review_task_sizing
 *   count(changed_files) > 10
 *     -> scope_quality: large_changed_file_count_review_scope
 *
 * A lesson is low_confidence=true only when it was produced by the
 * self-reported-success-without-evidence rule.
 *
 * INPUT:
 *   outcomes: list<{
 *     task_id:           string
 *     result?:           string (success|give_back|repair|failed) (default '')
 *     commit_sha?:       string (default '')
 *     tests_run?:        bool (default false)
 *     give_back_reason?: string (default '')
 *     changed_files?:    list<string> (default [])
 *     worker_id?:        string (default '')
 *     task_family?:      string (default '')
 *     elapsed_minutes?:  int (default 0)
 *   }>
 *
 * OUTPUT:
 *   { schema, lessons: {
 *       spec_quality: list, routing_quality: list, proof_quality: list,
 *       scope_quality: list, implementation_risk: list,
 *   }, lesson_count }
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPostImplementationLessonExtractor
{
    public const SCHEMA = 'atlas.external_brain.post_implementation_lesson_extractor.v1';

    public const CATEGORIES = [
        'spec_quality',
        'routing_quality',
        'proof_quality',
        'scope_quality',
        'implementation_risk',
    ];

    private const LONG_ELAPSED_MINUTES = 60;

    private const LARGE_CHANGED_FILE_COUNT = 10;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function extract(array $input): array
    {
        $outcomes = is_array($input['outcomes'] ?? null) ? $input['outcomes'] : [];

        $lessons = array_fill_keys(self::CATEGORIES, []);

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome) || ! isset($outcome['task_id'])) {
                continue;
            }

            $taskId = (string) $outcome['task_id'];
            $result = (string) ($outcome['result'] ?? '');
            $commitSha = (string) ($outcome['commit_sha'] ?? '');
            $testsRun = (bool) ($outcome['tests_run'] ?? false);
            $giveBackReason = strtolower((string) ($outcome['give_back_reason'] ?? ''));
            $changedFiles = is_array($outcome['changed_files'] ?? null) ? $outcome['changed_files'] : [];
            $elapsedMinutes = max(0, (int) ($outcome['elapsed_minutes'] ?? 0));

            if ($result === 'success') {
                if ($commitSha === '' || ! $testsRun) {
                    $lessons['proof_quality'][] = $this->lesson($taskId, 'self_reported_success_without_evidence', true);
                } else {
                    $lessons['proof_quality'][] = $this->lesson($taskId, 'verified_success_with_commit_and_tests', false);
                }
            }

            if ($result === 'give_back') {
                if (str_contains($giveBackReason, 'scope')) {
                    $lessons['scope_quality'][] = $this->lesson($taskId, 'give_back_due_to_scope_mismatch', false);
                } elseif (str_contains($giveBackReason, 'spec') || str_contains($giveBackReason, 'objective') || str_contains($giveBackReason, 'acceptance')) {
                    $lessons['spec_quality'][] = $this->lesson($taskId, 'give_back_due_to_unclear_spec', false);
                } else {
                    $lessons['routing_quality'][] = $this->lesson($taskId, 'give_back_unclassified_reason', false);
                }
            }

            if ($result === 'repair') {
                $lessons['implementation_risk'][] = $this->lesson($taskId, 'required_repair_after_initial_attempt', false);
            }

            if ($result === 'failed') {
                $lessons['implementation_risk'][] = $this->lesson($taskId, 'attempt_failed', false);
            }

            if ($elapsedMinutes >= self::LONG_ELAPSED_MINUTES) {
                $lessons['implementation_risk'][] = $this->lesson($taskId, 'long_elapsed_time_review_task_sizing', false);
            }

            if (count($changedFiles) > self::LARGE_CHANGED_FILE_COUNT) {
                $lessons['scope_quality'][] = $this->lesson($taskId, 'large_changed_file_count_review_scope', false);
            }
        }

        $lessonCount = array_sum(array_map('count', $lessons));

        return [
            'schema' => self::SCHEMA,
            'lessons' => $lessons,
            'lesson_count' => $lessonCount,
        ];
    }

    /** @return array<string,mixed> */
    private function lesson(string $taskId, string $lesson, bool $lowConfidence): array
    {
        return [
            'task_id' => $taskId,
            'lesson' => $lesson,
            'low_confidence' => $lowConfidence,
        ];
    }
}

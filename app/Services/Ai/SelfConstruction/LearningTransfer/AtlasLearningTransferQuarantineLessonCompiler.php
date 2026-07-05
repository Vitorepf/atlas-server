<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Pure compiler that compiles quarantine history into negative lessons that
 * prevent poison packet families from being reintroduced.
 *
 * Negative lesson types:
 *   - forbidden_target: target family is permanently forbidden
 *   - malformed_scope: scope was malformed and must not be re-originated
 *   - duplicate_satisfied_work: work was already completed, do not re-emit
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasLearningTransferQuarantineLessonCompiler
{
    public const SCHEMA = 'atlas.learning_transfer.quarantine_lesson_compiler.v1';

    public const LESSON_FORBIDDEN_TARGET = 'forbidden_target';
    public const LESSON_MALFORMED_SCOPE = 'malformed_scope';
    public const LESSON_DUPLICATE_SATISFIED = 'duplicate_satisfied_work';

    /**
     * @param  array<int, array<string, mixed>>  $quarantineHistory
     * @return array<string, mixed>
     */
    public function compile(array $quarantineHistory): array
    {
        $lessons = [];

        foreach ($quarantineHistory as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $reason = strtolower(trim((string) ($entry['reason'] ?? '')));
            $targetFamily = (string) ($entry['target_family'] ?? '');
            $taskId = (string) ($entry['task_id'] ?? '');

            $lessonType = match ($reason) {
                'forbidden_self_target', 'forbidden_axis', 'forbidden_files_in_allowed' => self::LESSON_FORBIDDEN_TARGET,
                'missing_scope', 'missing_objective', 'missing_allowed_files', 'incomplete_spec' => self::LESSON_MALFORMED_SCOPE,
                'duplicate_satisfied_work', 'already_completed', 'duplicate_target' => self::LESSON_DUPLICATE_SATISFIED,
                default => null,
            };

            if ($lessonType !== null && $targetFamily !== '') {
                $lessons[] = [
                    'lesson_type' => $lessonType,
                    'target_family' => $targetFamily,
                    'reason' => $reason,
                    'source_task_id' => $taskId,
                    'action' => 'do_not_reintroduce',
                ];
            }
        }

        // Deduplicate by lesson_type + target_family + reason.
        $seen = [];
        $deduped = [];
        foreach ($lessons as $lesson) {
            $key = $lesson['lesson_type'].':'.$lesson['target_family'].':'.$lesson['reason'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $lesson;
            }
        }

        // Sort deterministically.
        usort($deduped, static function (array $a, array $b): int {
            return strcmp($a['lesson_type'], $b['lesson_type'])
                ?: strcmp($a['target_family'], $b['target_family'])
                ?: strcmp($a['reason'], $b['reason']);
        });

        $forbiddenTargets = array_values(array_filter($deduped, static fn (array $l): bool => $l['lesson_type'] === self::LESSON_FORBIDDEN_TARGET));
        $malformedScopes = array_values(array_filter($deduped, static fn (array $l): bool => $l['lesson_type'] === self::LESSON_MALFORMED_SCOPE));
        $duplicateSatisfied = array_values(array_filter($deduped, static fn (array $l): bool => $l['lesson_type'] === self::LESSON_DUPLICATE_SATISFIED));

        return [
            'schema_version' => self::SCHEMA,
            'lessons' => $deduped,
            'forbidden_target_lessons' => $forbiddenTargets,
            'malformed_scope_lessons' => $malformedScopes,
            'duplicate_satisfied_lessons' => $duplicateSatisfied,
            'total_lessons' => count($deduped),
        ];
    }
}

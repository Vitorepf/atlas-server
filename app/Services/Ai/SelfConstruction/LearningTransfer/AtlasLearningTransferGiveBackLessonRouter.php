<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Routes give_back lessons into scope, objective, acceptance, evidence or
 * duplicate-prevention repair channels.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasLearningTransferGiveBackLessonRouter
{
    public const SCHEMA = 'atlas.learning_transfer.give_back_lesson_router.v1';

    public const CHANNEL_SCOPE = 'scope_repair';
    public const CHANNEL_OBJECTIVE = 'objective_repair';
    public const CHANNEL_ACCEPTANCE = 'acceptance_repair';
    public const CHANNEL_EVIDENCE = 'evidence_repair';
    public const CHANNEL_DUPLICATE_PREVENTION = 'duplicate_prevention';
    public const CHANNEL_QUARANTINE = 'quarantine';
    public const CHANNEL_UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function route(array $input): array
    {
        $lessons = is_array($input['lessons'] ?? null) ? $input['lessons'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $routed = [];
        foreach ($lessons as $lesson) {
            if (! is_array($lesson)) {
                continue;
            }

            $class = (string) ($lesson['class'] ?? '');
            $reason = (string) ($lesson['reason'] ?? '');
            $channel = $this->channelFor($class, $reason);

            $routed[] = [
                'class' => $class,
                'reason' => $reason,
                'channel' => $channel,
                'recommendation' => $this->recommendationFor($channel, $lesson),
            ];
        }

        usort($routed, static function (array $a, array $b): int {
            $priority = [
                self::CHANNEL_QUARANTINE => 0,
                self::CHANNEL_DUPLICATE_PREVENTION => 1,
                self::CHANNEL_SCOPE => 2,
                self::CHANNEL_OBJECTIVE => 3,
                self::CHANNEL_ACCEPTANCE => 4,
                self::CHANNEL_EVIDENCE => 5,
                self::CHANNEL_UNKNOWN => 6,
            ];

            return ($priority[$a['channel']] ?? 99) <=> ($priority[$b['channel']] ?? 99)
                ?: strcmp($a['class'], $b['class']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'routed_lessons' => $routed,
            'routed_count' => count($routed),
        ];
    }

    private function channelFor(string $class, string $reason): string
    {
        $lower = strtolower($class.' '.$reason);

        return match (true) {
            str_contains($lower, 'duplicate') || str_contains($lower, 'already_exists') => self::CHANNEL_DUPLICATE_PREVENTION,
            str_contains($lower, 'forbidden') || str_contains($lower, 'quarantine') => self::CHANNEL_QUARANTINE,
            str_contains($lower, 'scope') || str_contains($lower, 'allowed_files') => self::CHANNEL_SCOPE,
            str_contains($lower, 'objective') => self::CHANNEL_OBJECTIVE,
            str_contains($lower, 'acceptance') || str_contains($lower, 'criteria') => self::CHANNEL_ACCEPTANCE,
            str_contains($lower, 'evidence') || str_contains($lower, 'proof') => self::CHANNEL_EVIDENCE,
            default => self::CHANNEL_UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $lesson
     */
    private function recommendationFor(string $channel, array $lesson): string
    {
        $packetId = (string) ($lesson['packet_id'] ?? '');
        $prefix = $packetId !== '' ? "For {$packetId}: " : '';

        return match ($channel) {
            self::CHANNEL_SCOPE => $prefix.'Widen allowed_files to cover the missing implementation and test targets.',
            self::CHANNEL_OBJECTIVE => $prefix.'Rewrite the objective into one concrete deliverable with a clear success signal.',
            self::CHANNEL_ACCEPTANCE => $prefix.'Add runnable acceptance criteria bound to specific files or test classes.',
            self::CHANNEL_EVIDENCE => $prefix.'Require tests_or_gates_result and implementation_notes before requeue.',
            self::CHANNEL_DUPLICATE_PREVENTION => $prefix.'Check the done-set before emitting similar capability tasks.',
            self::CHANNEL_QUARANTINE => $prefix.'Quarantine the packet; do not requeue without operator review.',
            default => $prefix.'Investigate the root cause before routing to a repair channel.',
        };
    }
}

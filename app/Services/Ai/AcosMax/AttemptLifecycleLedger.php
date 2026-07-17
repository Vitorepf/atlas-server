<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class AttemptLifecycleLedger
{
    public const SCHEMA_VERSION = 'atlas.execution.attempt_lifecycle.v1';

    public const STATE_STARTED = 'started';

    public const STATE_COMPLETED = 'completed';

    public const STATE_CRASHED = 'crashed';

    public const STATE_TIMED_OUT = 'timed_out';

    public const STATE_ABANDONED = 'abandoned';

    /** @var list<string> */
    public const TERMINAL_STATES = [
        self::STATE_COMPLETED,
        self::STATE_CRASHED,
        self::STATE_TIMED_OUT,
        self::STATE_ABANDONED,
    ];

    public const REASON_TASK_OR_ATTEMPT_UNRESOLVABLE = 'task_or_attempt_unresolvable';

    public const REASON_DUPLICATE_ATTEMPT = 'duplicate_attempt';

    public const REASON_ATTEMPT_MISSING = 'attempt_missing';

    public const REASON_INVALID_TERMINAL_STATE = 'invalid_terminal_state';

    public const FIELD_ACCEPTED = 'accepted';

    /** @var array<string,array<string,mixed>> */
    private array $attempts = [];

    /**
     * @return array<string,mixed>
     */
    public function start(string $attemptId, string $taskId, ?int $startedAt = null): array
    {
        if (AiValueNormalizer::trimmedStringOrNull($attemptId) === null || AiValueNormalizer::trimmedStringOrNull($taskId) === null) {
            return [self::FIELD_ACCEPTED => false, 'reason' => self::REASON_TASK_OR_ATTEMPT_UNRESOLVABLE];
        }
        if (isset($this->attempts[$attemptId])) {
            return [self::FIELD_ACCEPTED => false, 'reason' => self::REASON_DUPLICATE_ATTEMPT];
        }

        $this->attempts[$attemptId] = [
            'attempt_id' => $attemptId,
            'task_id' => $taskId,
            'state' => self::STATE_STARTED,
            'started_at' => $startedAt ?? time(),
        ];

        return [self::FIELD_ACCEPTED => true, 'attempt' => $this->attempts[$attemptId]];
    }

    /**
     * @return array<string,mixed>
     */
    public function terminal(string $attemptId, string $state): array
    {
        if (! isset($this->attempts[$attemptId])) {
            return [self::FIELD_ACCEPTED => false, 'reason' => self::REASON_ATTEMPT_MISSING];
        }
        if (! in_array($state, self::TERMINAL_STATES, true)) {
            return [self::FIELD_ACCEPTED => false, 'reason' => self::REASON_INVALID_TERMINAL_STATE];
        }

        $this->attempts[$attemptId]['state'] = $state;

        return [self::FIELD_ACCEPTED => true, 'attempt' => $this->attempts[$attemptId]];
    }

    /**
     * @return array<string,mixed>
     */
    public function census(int $now, int $ttlSeconds): array
    {
        foreach ($this->attempts as $id => $attempt) {
            if ($attempt['state'] === self::STATE_STARTED && ($now - (int) (AiValueNormalizer::finiteFloatOrNull($attempt['started_at'] ?? null) ?? 0)) > $ttlSeconds) {
                $this->attempts[$id]['state'] = self::STATE_ABANDONED;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'attempts' => $this->attempts,
            'unterminated_count' => count(array_filter(
                $this->attempts,
                static fn (array $attempt): bool => $attempt['state'] === self::STATE_STARTED,
            )),
            'source' => [
                'outcome_without_attempt_allowed' => false,
                'attempt_id_deduped' => true,
            ],
        ];
    }
}

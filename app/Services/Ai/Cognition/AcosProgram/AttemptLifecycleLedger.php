<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\AcosProgram;

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
    public const FIELD_REASON = 'reason';
    public const FIELD_ATTEMPT = 'attempt';
    public const FIELD_ATTEMPT_ID = 'attempt_id';
    public const FIELD_TASK_ID = 'task_id';
    public const FIELD_STATE = 'state';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_ATTEMPTS = 'attempts';
    public const FIELD_STARTED_AT = 'started_at';
    public const FIELD_UNTERMINATED_COUNT = 'unterminated_count';
    public const FIELD_OUTCOME_WITHOUT_ATTEMPT_ALLOWED = 'outcome_without_attempt_allowed';
    public const FIELD_ATTEMPT_ID_DEDUPED = 'attempt_id_deduped';
    public const FIELD_SOURCE = 'source';

    /** @var array<string,array<string,mixed>> */
    private array $attempts = [];

    /**
     * @return array<string,mixed>
     */
    public function start(string $attemptId, string $taskId, ?int $startedAt = null): array
    {
        if (AiValueNormalizer::trimmedStringOrNull($attemptId) === null || AiValueNormalizer::trimmedStringOrNull($taskId) === null) {
            return [self::FIELD_ACCEPTED => false, self::FIELD_REASON => self::REASON_TASK_OR_ATTEMPT_UNRESOLVABLE];
        }
        if (isset($this->attempts[$attemptId])) {
            return [self::FIELD_ACCEPTED => false, self::FIELD_REASON => self::REASON_DUPLICATE_ATTEMPT];
        }

        $this->attempts[$attemptId] = [
            self::FIELD_ATTEMPT_ID => $attemptId,
            self::FIELD_TASK_ID => $taskId,
            self::FIELD_STATE => self::STATE_STARTED,
            self::FIELD_STARTED_AT => $startedAt ?? time(),
        ];

        return [self::FIELD_ACCEPTED => true, self::FIELD_ATTEMPT => $this->attempts[$attemptId]];
    }

    /**
     * @return array<string,mixed>
     */
    public function terminal(string $attemptId, string $state): array
    {
        if (! isset($this->attempts[$attemptId])) {
            return [self::FIELD_ACCEPTED => false, self::FIELD_REASON => self::REASON_ATTEMPT_MISSING];
        }
        if (! in_array($state, self::TERMINAL_STATES, true)) {
            return [self::FIELD_ACCEPTED => false, self::FIELD_REASON => self::REASON_INVALID_TERMINAL_STATE];
        }

        $this->attempts[$attemptId][self::FIELD_STATE] = $state;

        return [self::FIELD_ACCEPTED => true, self::FIELD_ATTEMPT => $this->attempts[$attemptId]];
    }

    /**
     * @return array<string,mixed>
     */
    public function census(int $now, int $ttlSeconds): array
    {
        foreach ($this->attempts as $id => $attempt) {
            if ($attempt[self::FIELD_STATE] === self::STATE_STARTED && ($now - (int) (AiValueNormalizer::finiteFloatOrNull($attempt[self::FIELD_STARTED_AT] ?? null) ?? 0)) > $ttlSeconds) {
                $this->attempts[$id][self::FIELD_STATE] = self::STATE_ABANDONED;
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_ATTEMPTS => $this->attempts,
            self::FIELD_UNTERMINATED_COUNT => count(array_filter(
                $this->attempts,
                static fn (array $attempt): bool => $attempt[self::FIELD_STATE] === self::STATE_STARTED,
            )),
            self::FIELD_SOURCE => [
                self::FIELD_OUTCOME_WITHOUT_ATTEMPT_ALLOWED => false,
                self::FIELD_ATTEMPT_ID_DEDUPED => true,
            ],
        ];
    }
}

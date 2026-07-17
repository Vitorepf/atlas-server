<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class AttemptLifecycleLedger
{
    public const SCHEMA_VERSION = 'atlas.execution.attempt_lifecycle.v1';

    /** @var list<string> */
    public const TERMINAL_STATES = ['completed', 'crashed', 'timed_out', 'abandoned'];

    /** @var array<string,array<string,mixed>> */
    private array $attempts = [];

    /**
     * @return array<string,mixed>
     */
    public function start(string $attemptId, string $taskId, ?int $startedAt = null): array
    {
        if (AiValueNormalizer::trimmedString($attemptId) === '' || AiValueNormalizer::trimmedString($taskId) === '') {
            return ['accepted' => false, 'reason' => 'task_or_attempt_unresolvable'];
        }
        if (isset($this->attempts[$attemptId])) {
            return ['accepted' => false, 'reason' => 'duplicate_attempt'];
        }

        $this->attempts[$attemptId] = [
            'attempt_id' => $attemptId,
            'task_id' => $taskId,
            'state' => 'started',
            'started_at' => $startedAt ?? time(),
        ];

        return ['accepted' => true, 'attempt' => $this->attempts[$attemptId]];
    }

    /**
     * @return array<string,mixed>
     */
    public function terminal(string $attemptId, string $state): array
    {
        if (! isset($this->attempts[$attemptId])) {
            return ['accepted' => false, 'reason' => 'attempt_missing'];
        }
        if (! in_array($state, self::TERMINAL_STATES, true)) {
            return ['accepted' => false, 'reason' => 'invalid_terminal_state'];
        }

        $this->attempts[$attemptId]['state'] = $state;

        return ['accepted' => true, 'attempt' => $this->attempts[$attemptId]];
    }

    /**
     * @return array<string,mixed>
     */
    public function census(int $now, int $ttlSeconds): array
    {
        foreach ($this->attempts as $id => $attempt) {
            if ($attempt['state'] === 'started' && ($now - (int) $attempt['started_at']) > $ttlSeconds) {
                $this->attempts[$id]['state'] = 'abandoned';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'attempts' => $this->attempts,
            'unterminated_count' => count(array_filter(
                $this->attempts,
                static fn (array $attempt): bool => $attempt['state'] === 'started',
            )),
            'source' => [
                'outcome_without_attempt_allowed' => false,
                'attempt_id_deduped' => true,
            ],
        ];
    }
}

<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiOutcomeLink;
use App\Support\AtlasSecurity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiOutcomeAttributionService
{
    public const OUTCOME_TYPES = [
        'task_created',
        'task_completed',
        'project_created',
        'project_plan_accepted',
        'blocker_resolved',
        'routine_created',
        'decision_recorded',
        'conversation_continued',
        'user_reasked_same_intent',
        'user_abandoned_thread',
        'provider_switched_after_bad_answer',
        'human_marked_useful',
        'human_marked_not_useful',
        'human_marked_wrong_context',
        'human_marked_too_slow',
        'human_marked_too_expensive',
        'human_marked_unsafe',
        'human_dismissed',
    ];

    /**
     * @param  array<string,mixed>  $data
     */
    public function record(array $data): ?AiOutcomeLink
    {
        if (! Schema::hasTable('ai_outcome_links')) {
            return null;
        }

        $outcomeType = $this->outcomeType($data['outcome_type'] ?? null);
        if (! $outcomeType) {
            throw new \InvalidArgumentException('Invalid AI outcome type.');
        }

        $payload = [
            'trace_id' => $this->uuid($data['trace_id'] ?? null),
            'thread_id' => $this->uuid($data['thread_id'] ?? null),
            'session_id' => $this->uuid($data['session_id'] ?? null),
            'outcome_type' => $outcomeType,
            'target_type' => $this->limitedString($data['target_type'] ?? null, 80),
            'target_id' => $this->uuid($data['target_id'] ?? null),
            'value_score' => $this->score($data['value_score'] ?? null),
            'confidence' => $this->confidence($data['confidence'] ?? null),
            'source' => $this->limitedString($data['source'] ?? 'system', 40) ?? 'system',
            'occurred_at' => $data['occurred_at'] ?? now(),
            'metadata' => AtlasSecurity::redactArray(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
            'created_at' => now(),
        ];

        $existing = AiOutcomeLink::query()
            ->where('trace_id', $payload['trace_id'])
            ->where('outcome_type', $payload['outcome_type'])
            ->where('target_type', $payload['target_type'])
            ->where('target_id', $payload['target_id'])
            ->first();

        if ($existing) {
            $existing->forceFill(Arr::except($payload, ['created_at']))->save();

            return $existing->refresh();
        }

        return AiOutcomeLink::query()->create($payload);
    }

    private function outcomeType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array($value, self::OUTCOME_TYPES, true) ? $value : null;
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    private function confidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function limitedString(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}

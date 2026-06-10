<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use Throwable;

class AuditLogService
{
    private const SENSITIVE_KEYS = [
        'content_text',
        'input_text',
        'operator_input',
        'prompt',
        'raw_source',
        'raw_excerpt',
        'text',
        'transcription',
        'response_text',
        'result_text',
    ];

    public function record(string $eventType, array $payload): ?AuditEvent
    {
        if (! DatabaseTableAvailability::has('audit_events')) {
            return null;
        }

        try {
            $privacy = $this->arrayValue($payload['privacy'] ?? []);
            $evidence = $this->redactIfNeeded($this->arrayValue($payload['evidence'] ?? []), $privacy);

            return AuditEvent::query()->create([
                'event_type' => $eventType,
                'subject_type' => $payload['subject_type'] ?? null,
                'subject_id' => $payload['subject_id'] ?? null,
                'actor_type' => $payload['actor_type'] ?? 'system',
                'actor_id' => $payload['actor_id'] ?? null,
                'severity' => $payload['severity'] ?? 'info',
                'summary' => $this->summary($payload['summary'] ?? $eventType, $privacy),
                'evidence' => $evidence,
                'privacy' => $privacy,
                'refs' => $this->arrayValue($payload['refs'] ?? []),
                'metadata' => $this->arrayValue($payload['metadata'] ?? []),
                'occurred_at' => $payload['occurred_at'] ?? now(),
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    public function redactIfNeeded(array $payload, array $privacy): array
    {
        $sensitivity = $privacy['sensitivity'] ?? null;
        if (! in_array($sensitivity, ['private', 'sensitive'], true)) {
            return $payload;
        }

        return $this->redactRecursive($payload);
    }

    private function redactRecursive(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::SENSITIVE_KEYS, true)) {
                $redacted[$key] = $this->redactedValue($value);

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactRecursive($value);

                continue;
            }

            $redacted[$key] = is_string($value) && mb_strlen($value) > 220
                ? Str::limit($value, 220)
                : $value;
        }

        return $redacted;
    }

    private function redactedValue(mixed $value): array
    {
        $hash = is_scalar($value) ? hash('sha256', (string) $value) : null;

        return [
            'redacted' => true,
            'sha256' => $hash,
        ];
    }

    private function summary(mixed $summary, array $privacy): string
    {
        $text = is_string($summary) && trim($summary) !== '' ? trim($summary) : 'Audit event';
        if (($privacy['sensitivity'] ?? null) === 'sensitive') {
            return Str::limit($text, 180);
        }

        return Str::limit($text, 240);
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiTelemetryEvent;
use App\Support\AtlasSecurity;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiTelemetryCollector
{
    public const SURFACES = [
        'mobile',
        'cli',
        'server',
        'worker',
        'scheduler',
        'eval',
    ];

    public const RUNTIMES = [
        'ios',
        'android',
        'mac_cli',
        'laravel',
        'worker',
        'scheduler',
        'test',
    ];

    /**
     * @return array{event:AiTelemetryEvent,duplicate:bool}
     */
    public function record(array $event): array
    {
        $payload = $this->normalize($event);

        $existing = AiTelemetryEvent::query()
            ->where('event_key', $payload['event_key'])
            ->first();

        if ($existing) {
            return ['event' => $existing, 'duplicate' => true];
        }

        return [
            'event' => AiTelemetryEvent::query()->create($payload),
            'duplicate' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array{accepted:int,duplicates:int,rejected:int,events:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
     */
    public function recordBatch(array $events): array
    {
        $accepted = 0;
        $duplicates = 0;
        $rejected = 0;
        $stored = [];
        $errors = [];

        foreach (array_values($events) as $index => $event) {
            try {
                $result = $this->record($event);
                $stored[] = [
                    'id' => $result['event']->id,
                    'event_key' => $result['event']->event_key,
                    'duplicate' => $result['duplicate'],
                ];

                if ($result['duplicate']) {
                    $duplicates++;
                } else {
                    $accepted++;
                }
            } catch (\Throwable $exception) {
                $rejected++;
                $errors[] = [
                    'index' => $index,
                    'message' => Str::limit($exception->getMessage(), 240, '...'),
                ];
            }
        }

        return compact('accepted', 'duplicates', 'rejected', 'errors') + [
            'events' => $stored,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalize(array $event): array
    {
        $surface = $this->nullableString($event['surface'] ?? null);
        if (! $surface || ! in_array($surface, self::SURFACES, true)) {
            throw new InvalidArgumentException('Invalid telemetry surface.');
        }

        $runtime = $this->nullableString($event['runtime'] ?? null);
        if ($runtime && ! in_array($runtime, self::RUNTIMES, true)) {
            throw new InvalidArgumentException('Invalid telemetry runtime.');
        }

        $eventName = $this->nullableString($event['event_name'] ?? null);
        if (! $eventName) {
            throw new InvalidArgumentException('Telemetry event_name is required.');
        }

        $metadata = $this->sanitizePayload($event['metadata'] ?? []);
        $privacy = $this->sanitizePayload($event['privacy'] ?? []);

        return [
            'event_key' => $this->eventKey($event['event_key'] ?? null),
            'correlation_id' => $this->uuid($event['correlation_id'] ?? null),
            'trace_id' => $this->uuid($event['trace_id'] ?? null),
            'thread_id' => $this->uuid($event['thread_id'] ?? null),
            'session_id' => $this->uuid($event['session_id'] ?? null),
            'ai_job_id' => $this->uuid($event['ai_job_id'] ?? null),
            'ai_job_attempt_id' => $this->uuid($event['ai_job_attempt_id'] ?? null),
            'client_id' => $this->uuid($event['client_id'] ?? null),
            'surface' => $surface,
            'runtime' => $runtime,
            'app_version' => $this->limitedString($event['app_version'] ?? null, 64),
            'cli_version' => $this->limitedString($event['cli_version'] ?? null, 64),
            'provider' => $this->limitedString($event['provider'] ?? null, 80),
            'model' => $this->limitedString($event['model'] ?? null, 120),
            'agent_slug' => $this->limitedString($event['agent_slug'] ?? null, 120),
            'event_name' => $this->limitedString($eventName, 100) ?: $eventName,
            'event_phase' => $this->limitedString($event['event_phase'] ?? null, 60),
            'occurred_at_client' => $this->nullableString($event['occurred_at_client'] ?? null),
            'received_at' => now(),
            'duration_ms' => $this->nonNegativeInteger($event['duration_ms'] ?? null),
            'numeric_value' => is_numeric($event['numeric_value'] ?? null) ? (float) $event['numeric_value'] : null,
            'unit' => $this->limitedString($event['unit'] ?? null, 32),
            'metadata' => $metadata,
            'privacy' => $privacy,
            'schema_version' => max(1, (int) ($event['schema_version'] ?? 1)),
            'created_at' => now(),
        ];
    }

    private function eventKey(mixed $value): string
    {
        $key = $this->nullableString($value);

        return $key
            ? Str::limit($key, 180, '')
            : 'server:'.(string) Str::uuid();
    }

    private function uuid(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if (! $value) {
            return null;
        }

        return Str::isUuid($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limitedString(mixed $value, int $limit): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : Str::limit($value, $limit, '');
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @return array<string,mixed>
     */
    private function sanitizePayload(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $payload = AtlasSecurity::redactArray($payload);
        $limit = max(1000, (int) config('atlas.ai_metrics.telemetry_max_metadata_bytes', 12000));
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded) || strlen($encoded) <= $limit) {
            return Arr::where($payload, fn (mixed $value): bool => $value !== null);
        }

        return [
            '_truncated' => true,
            'original_bytes' => strlen($encoded),
            'excerpt' => Str::limit($encoded, $limit, '...'),
        ];
    }
}

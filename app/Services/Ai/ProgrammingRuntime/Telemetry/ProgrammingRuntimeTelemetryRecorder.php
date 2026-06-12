<?php

namespace App\Services\Ai\ProgrammingRuntime\Telemetry;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records Programming Runtime telemetry events into a dedicated, queryable
 * ledger. Secrets are redacted before persistence (via AtlasSecurity), the
 * payload is canonicalised for stable hashing, and identical events are
 * deduplicated by `event_hash`.
 *
 * The recorder is intentionally tolerant: when the backing table is missing
 * (typical in non-migrated environments), `record()` returns `null` instead
 * of throwing. Callers should treat telemetry as best-effort — it must never
 * block the primary runtime path.
 */
class ProgrammingRuntimeTelemetryRecorder
{
    public function __construct(
        private readonly ProviderCostEstimator $costEstimator = new ProviderCostEstimator(),
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public function record(array $input): ?AiProgrammingRuntimeTelemetryEvent
    {
        if (! DatabaseTableAvailability::has('ai_programming_runtime_telemetry_events')) {
            return null;
        }

        $eventName = $this->string($input['event_name'] ?? null);
        if ($eventName === null) {
            throw new InvalidArgumentException('programming_runtime_telemetry_requires_event_name');
        }

        $selectedCore = $this->string($input['selected_core'] ?? null);
        if ($selectedCore !== null && ! in_array($selectedCore, ProgrammingRuntimeTelemetryCanon::SELECTED_CORES, true)) {
            throw new InvalidArgumentException('programming_runtime_telemetry_invalid_selected_core');
        }

        $metadata = $this->sanitizeMetadata($input['metadata'] ?? []);

        $payload = [
            'schema_version' => ProgrammingRuntimeTelemetryCanon::EVENT_SCHEMA_VERSION,
            'event_name' => $eventName,
            'event_phase' => $this->string($input['event_phase'] ?? null),
            'flow' => $this->string($input['flow'] ?? null),
            'selected_core' => $selectedCore,
            'run_id' => $this->limited($input['run_id'] ?? null, 160),
            'mission_id' => $this->uuid($input['mission_id'] ?? null),
            'work_order_id' => $this->uuid($input['work_order_id'] ?? null),
            'obra_id' => $this->uuid($input['obra_id'] ?? null),
            'route_decision_id' => $this->uuid($input['route_decision_id'] ?? null),
            'rag_gate_status' => $this->enum(
                $input['rag_gate_status'] ?? null,
                ProgrammingRuntimeTelemetryCanon::RAG_GATE_STATUSES,
            ),
            'context_sufficiency' => $this->score($input['context_sufficiency'] ?? null),
            'execution_status' => $this->enum(
                $input['execution_status'] ?? null,
                ProgrammingRuntimeTelemetryCanon::EXECUTION_STATUSES,
            ),
            'test_status' => $this->enum(
                $input['test_status'] ?? null,
                ProgrammingRuntimeTelemetryCanon::TEST_STATUSES,
            ),
            'repair_attempt_count' => $this->nonNegativeInt($input['repair_attempt_count'] ?? null, 65535),
            'evidence_completeness' => $this->score($input['evidence_completeness'] ?? null),
            'certification_status' => $this->enum(
                $input['certification_status'] ?? null,
                ProgrammingRuntimeTelemetryCanon::CERTIFICATION_STATUSES,
            ),
            'blocker_count' => $this->nonNegativeInt($input['blocker_count'] ?? null, 65535),
            'duration_ms' => $this->nonNegativeInt($input['duration_ms'] ?? null),
            // L3-10: MEASURE the cost axis. If the caller already passed a
            // measured cost we keep it; otherwise we derive one from real
            // execution signals (provider-reported tokens, or wall-clock
            // runtime for local token-free providers). Never fabricated — if no
            // signal exists, this stays null (honestly "unknown").
            'cost_estimate_usd' => $this->nonNegativeFloat($this->costEstimator->estimate($input)),
            'metadata' => $metadata,
            'occurred_at' => $this->timestamp($input['occurred_at'] ?? null),
        ];

        $payload['event_hash'] = $this->hash($payload);

        return AiProgrammingRuntimeTelemetryEvent::query()->firstOrCreate(
            ['event_hash' => $payload['event_hash']],
            $payload,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $canonical = [
            'event_name' => $payload['event_name'],
            'event_phase' => $payload['event_phase'],
            'flow' => $payload['flow'],
            'selected_core' => $payload['selected_core'],
            'run_id' => $payload['run_id'],
            'mission_id' => $payload['mission_id'],
            'work_order_id' => $payload['work_order_id'],
            'obra_id' => $payload['obra_id'],
            'route_decision_id' => $payload['route_decision_id'],
            'rag_gate_status' => $payload['rag_gate_status'],
            'context_sufficiency' => $payload['context_sufficiency'],
            'execution_status' => $payload['execution_status'],
            'test_status' => $payload['test_status'],
            'repair_attempt_count' => $payload['repair_attempt_count'],
            'evidence_completeness' => $payload['evidence_completeness'],
            'certification_status' => $payload['certification_status'],
            'blocker_count' => $payload['blocker_count'],
            'duration_ms' => $payload['duration_ms'],
            'cost_estimate_usd' => $payload['cost_estimate_usd'],
            'metadata' => $payload['metadata'],
        ];
        ksort($canonical);
        $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    /**
     * @return array<string,mixed>
     */
    private function sanitizeMetadata(mixed $value): array
    {
        $payload = is_array($value) ? $value : [];
        $payload = AtlasSecurity::redactArray($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || strlen($encoded) <= 8000) {
            return $payload;
        }

        return [
            '_truncated' => true,
            'original_bytes' => strlen($encoded),
            'excerpt' => Str::limit($encoded, 8000, '...'),
        ];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limited(mixed $value, int $max): ?string
    {
        $value = $this->string($value);

        return $value === null ? null : Str::limit($value, $max, '');
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enum(mixed $value, array $allowed): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function nonNegativeInt(mixed $value, int $max = PHP_INT_MAX): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        if ($int < 0) {
            return null;
        }

        return $int > $max ? $max : $int;
    }

    private function nonNegativeFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float < 0 ? null : $float;
    }

    private function uuid(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        return Str::isUuid($value) ? $value : null;
    }

    private function timestamp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return now();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (is_numeric($value)) {
            return now()->setTimestamp((int) $value);
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }
}

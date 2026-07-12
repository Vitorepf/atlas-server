<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Coverage;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class EngineeringExecutionCoverage
{
    public const SCHEMA_VERSION = 'atlas.engineering_execution_coverage.v2';
    public const MODE_OBSERVE = 'observe';
    public const MODE_ENFORCE = 'enforce';
    public const EVENT_TYPE = 'engineering_execution_coverage.v2';

    private const STATUS_OK = 'ok';
    private const STATUS_FAILED = 'failed';
    private const STATUS_PENDING_DATA = 'pending_data';
    private const STATUS_OBSERVE_INCOMPLETE = 'observe_incomplete';

    /**
     * @var list<string>
     */
    private const COMPLETE_FIELDS = [
        'mode',
        'surface',
        'run_id',
        'execution_order_hash',
        'provider_receipt',
        'workspace_delta_hash',
        'acceptance_receipt',
        'release_receipt',
        'terminal_outcome',
    ];

    /** @param array<string,mixed> $event */
    public function record(array $event): void
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return;
        }

        $payload = $this->coveragePayload($event);
        $eventId = $this->string($event['event_id'] ?? (string) Str::ulid(), 32);
        // recorded_at is observational metadata; excluding it keeps a deterministic
        // event id replay-safe while still detecting semantic payload conflicts.
        $payloadHash = $this->hashPayload(array_diff_key($payload, ['recorded_at' => true]));
        $occurredAt = $this->occurredAt($event['occurred_at'] ?? null);
        $runId = $this->nullableString($payload['run_id'] ?? null, 80);

        $row = [
            'event_id' => $eventId,
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $this->string($event['tenant_id'] ?? 'default', 120),
            'operator_id' => $this->string($event['operator_id'] ?? 'system', 120),
            'envelope_id' => $this->string($event['envelope_id'] ?? ($runId !== null ? 'engineering_execution:'.$runId : 'engineering_execution'), 80),
            'receipt_id' => $this->nullableString($event['receipt_id'] ?? $payload['release_receipt'] ?? $payload['acceptance_receipt'] ?? null, 80),
            'trace_id' => $this->nullableString($event['trace_id'] ?? null, 80),
            'correlation_id' => $this->string($event['correlation_id'] ?? $runId ?? (string) Str::ulid(), 120),
            'causation_id' => $this->nullableString($event['causation_id'] ?? null, 80),
            'event_type' => self::EVENT_TYPE,
            'emitter_stage' => $this->string($event['emitter_stage'] ?? 'atlas.engineering_execution_coverage', 120),
            'emitter_version' => $this->string($event['emitter_version'] ?? self::SCHEMA_VERSION, 80),
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'occurred_at' => $occurredAt,
        ];

        if (DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_type')) {
            $row['scope_type'] = 'engineering_execution_surface';
        }
        if (DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_id')) {
            $row['scope_id'] = $this->nullableString($payload['surface'] ?? null, 80);
        }
        if (DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'event_hash')) {
            $row['event_hash'] = $this->hashPayload([
                'event_id' => $eventId,
                'event_type' => self::EVENT_TYPE,
                'payload_hash' => $payloadHash,
                'occurred_at' => $occurredAt->toISOString(),
            ]);
        }

        $existing = AtlasLedgerEvent::query()->where('event_id', $eventId)->first();
        if ($existing instanceof AtlasLedgerEvent) {
            if ((string) $existing->getAttribute('payload_hash') === $payloadHash) {
                return;
            }

            throw new \RuntimeException('engineering_execution_coverage_event_conflict');
        }

        AtlasLedgerEvent::query()->create($row);
    }

    /** @return array<string,mixed> */
    public function report(?string $mode = null): array
    {
        $mode = $this->mode($mode);
        $surfaces = EngineeringExecutionSurfaceRegistry::all();

        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return $this->reportPayload(
                mode: $mode,
                surfaces: $surfaces,
                totalEvents: 0,
                completeEvents: 0,
                incompleteSamples: [],
            );
        }

        $events = AtlasLedgerEvent::query()
            ->where('event_type', self::EVENT_TYPE)
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_id')
            ->get(['event_id', 'payload', 'occurred_at']);

        $totalEvents = 0;
        $completeEvents = 0;
        $incompleteSamples = [];

        foreach ($events as $ledgerEvent) {
            $payload = $this->payloadArray($ledgerEvent->payload);
            if (($payload['mode'] ?? null) !== $mode) {
                continue;
            }

            $totalEvents++;
            $missing = $this->missingCompleteFields($payload);
            if ($missing === []) {
                $completeEvents++;

                continue;
            }

            if (count($incompleteSamples) < 10) {
                $incompleteSamples[] = [
                    'event_id' => (string) $ledgerEvent->event_id,
                    'surface' => $this->nullableString($payload['surface'] ?? null, 120),
                    'run_id' => $this->nullableString($payload['run_id'] ?? null, 120),
                    'missing_fields' => $missing,
                    'kernel_routed' => $payload['kernel_routed'] ?? null,
                    'occurred_at' => $ledgerEvent->occurred_at?->toISOString(),
                ];
            }
        }

        return $this->reportPayload(
            mode: $mode,
            surfaces: $surfaces,
            totalEvents: $totalEvents,
            completeEvents: $completeEvents,
            incompleteSamples: $incompleteSamples,
        );
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function coveragePayload(array $event): array
    {
        $payload = $this->canonicalize(array_replace($event, [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $this->mode($event['mode'] ?? null),
            'surface' => $this->nullableString($event['surface'] ?? null, 120),
            'recorded_at' => CarbonImmutable::now()->toISOString(),
        ]));

        $missing = $this->missingCompleteFields($payload);
        $payload['complete'] = $missing === [];
        $payload['missing_fields'] = $missing;

        return $this->canonicalize($payload);
    }

    /**
     * @param  list<array{id:string,path:string,owner:string,mutative:bool}>  $surfaces
     * @param  list<array<string,mixed>>  $incompleteSamples
     * @return array<string,mixed>
     */
    private function reportPayload(
        string $mode,
        array $surfaces,
        int $totalEvents,
        int $completeEvents,
        array $incompleteSamples,
    ): array {
        $incompleteEvents = $totalEvents - $completeEvents;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'status' => $this->status($mode, $totalEvents, $incompleteEvents),
            'surfaces' => $surfaces,
            'counts' => [
                'total_events' => $totalEvents,
                'complete_events' => $completeEvents,
                'incomplete_events' => $incompleteEvents,
            ],
            'incomplete_samples' => $incompleteSamples,
        ];
    }

    private function status(string $mode, int $totalEvents, int $incompleteEvents): string
    {
        if ($totalEvents === 0) {
            return self::STATUS_PENDING_DATA;
        }

        if ($incompleteEvents === 0) {
            return self::STATUS_OK;
        }

        return $mode === self::MODE_ENFORCE
            ? self::STATUS_FAILED
            : self::STATUS_OBSERVE_INCOMPLETE;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function missingCompleteFields(array $payload): array
    {
        $missing = [];
        foreach (self::COMPLETE_FIELDS as $field) {
            if (! $this->nonEmptyString($payload[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function mode(mixed $mode): string
    {
        return $mode === self::MODE_ENFORCE ? self::MODE_ENFORCE : self::MODE_OBSERVE;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function occurredAt(mixed $value): CarbonImmutable
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                return CarbonImmutable::now();
            }
        }

        return CarbonImmutable::now();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalize(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash(
            'sha256',
            json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function string(mixed $value, int $max): string
    {
        $value = trim((string) $value);

        return Str::limit($value !== '' ? $value : 'unknown', $max, '');
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $max, '') : null;
    }
}

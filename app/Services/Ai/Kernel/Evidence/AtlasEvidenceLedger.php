<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AtlasLedgerEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasEvidenceLedger
{
    public const SCHEMA_VERSION = 'atlas.ledger_event.v1';

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function record(
        LedgerEventType $type,
        array $payload,
        array $context = [],
    ): ?AtlasLedgerEvent {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return null;
        }

        $payload = $this->canonicalize($payload);
        $occurredAt = isset($context['occurred_at'])
            ? CarbonImmutable::parse($context['occurred_at'])
            : CarbonImmutable::now();

        return AtlasLedgerEvent::query()->create([
            'event_id' => $this->string($context['event_id'] ?? (string) Str::ulid(), 32),
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $this->string($context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'), 120),
            'operator_id' => $this->string($context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'), 120),
            'envelope_id' => $this->string($context['envelope_id'] ?? data_get($payload, 'envelope_id', 'unknown'), 80),
            'receipt_id' => $this->nullableString($context['receipt_id'] ?? data_get($payload, 'receipt_id'), 80),
            'trace_id' => $this->nullableString($context['trace_id'] ?? data_get($payload, 'trace_id'), 80),
            'correlation_id' => $this->string($context['correlation_id'] ?? data_get($payload, 'correlation_id', data_get($payload, 'envelope_id', (string) Str::ulid())), 120),
            'causation_id' => $this->nullableString($context['causation_id'] ?? null, 80),
            'event_type' => $type->value,
            'emitter_stage' => $this->string($context['emitter_stage'] ?? 'atlas.kernel', 120),
            'emitter_version' => $this->string($context['emitter_version'] ?? 'v1', 80),
            'payload' => $payload,
            'payload_hash' => $this->payloadHash($payload),
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context
     * @return array{envelope_created:?AtlasLedgerEvent,decision_issued:?AtlasLedgerEvent}
     */
    public function recordDecisionIssued(array $receipt, array $context = []): array
    {
        $ledgerContext = [
            'tenant_id' => $context['tenant_id'] ?? data_get($receipt, 'metadata.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($receipt, 'metadata.operator_id', 'system'),
            'envelope_id' => $receipt['envelope_id'] ?? 'unknown',
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'trace_id' => $context['trace_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? ($receipt['envelope_id'] ?? null),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.decide',
            'emitter_version' => $context['emitter_version'] ?? 'atlas-decide-v2',
        ];

        $envelopeEvent = $this->record(LedgerEventType::EnvelopeCreated, [
            'envelope_id' => $receipt['envelope_id'] ?? null,
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'domain' => $receipt['domain'] ?? null,
            'flow' => $receipt['flow'] ?? null,
            'inputs_hash' => $receipt['inputs_hash'] ?? null,
            'operator' => [
                'tenant_id' => $ledgerContext['tenant_id'],
                'operator_id' => $ledgerContext['operator_id'],
            ],
        ], $ledgerContext);

        $decisionEvent = $this->record(LedgerEventType::DecisionIssued, [
            'envelope_id' => $receipt['envelope_id'] ?? null,
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'schema_version' => $receipt['schema_version'] ?? null,
            'domain' => $receipt['domain'] ?? null,
            'flow' => $receipt['flow'] ?? null,
            'risk' => $receipt['risk'] ?? null,
            'provider_selection' => $receipt['provider_selection'] ?? [],
            'budgets' => $receipt['budgets'] ?? [],
            'required_gates' => $receipt['required_gates'] ?? [],
            'required_evidence' => $receipt['required_evidence'] ?? [],
            'repair_policy' => $receipt['repair_policy'] ?? [],
            'inputs_hash' => $receipt['inputs_hash'] ?? null,
            'receipt_hash' => $receipt['receipt_hash'] ?? null,
            'chain_hash' => $receipt['chain_hash'] ?? null,
            'issued_at' => $receipt['issued_at'] ?? null,
            'expires_at' => $receipt['expires_at'] ?? null,
        ], array_merge($ledgerContext, [
            'causation_id' => $envelopeEvent?->event_id,
        ]));

        return [
            'envelope_created' => $envelopeEvent,
            'decision_issued' => $decisionEvent,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForEnvelope(string $envelopeId): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return [];
        }

        return AtlasLedgerEvent::query()
            ->where('envelope_id', $envelopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $event->toArray())
            ->values()
            ->all();
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
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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

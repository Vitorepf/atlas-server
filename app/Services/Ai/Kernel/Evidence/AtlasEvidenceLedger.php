<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Failure\FailureClassifier;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use App\Services\Ai\Kernel\Repair\RepairDecision;
use App\Services\Ai\Kernel\Repair\RepairResult;
use App\Services\Ai\Kernel\Slo\KernelSloAssessment;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AtlasEvidenceLedger
{
    public const SCHEMA_VERSION = 'atlas.ledger_event.v1';

    public const SCHEMA_VERSION_V2 = 'atlas.ledger_event.v2';

    public const CHAIN_BASIS_HASH_CHAINED = 'hash_chained';

    public const CHAIN_BASIS_LEGACY_UNCHAINED = 'legacy_unchained';

    public const CHAIN_BASIS_FULL_ENVELOPE_V2 = 'full_envelope_v2';

    public const INTEGRITY_LEGACY_UNVERIFIED = 'legacy_unverified';

    public function __construct(
        private readonly FailureClassifier $failureClassifier,
        private readonly FailureHandlerRegistry $failureHandlers,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function record(
        LedgerEventType $type,
        array $payload,
        array $context = [],
    ): ?AtlasLedgerEvent {
        if (! $this->tableAvailable()) {
            return null;
        }

        $payload = $this->canonicalize($payload);
        $occurredAt = isset($context['occurred_at'])
            ? (function () use ($context): CarbonImmutable {
                try {
                    return CarbonImmutable::parse($context['occurred_at'])->utc()->startOfSecond();
                } catch (Throwable $e) {
                    return CarbonImmutable::now()->utc()->startOfSecond();
                }
            })()
            : CarbonImmutable::now()->utc()->startOfSecond();

        $eventId = $this->string($context['event_id'] ?? (string) Str::ulid(), 32);
        $tenantId = $this->string($context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'), 120);
        $operatorId = $this->string($context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'), 120);
        $envelopeId = $this->string($context['envelope_id'] ?? data_get($payload, 'envelope_id', 'unknown'), 80);
        $receiptId = $this->nullableString($context['receipt_id'] ?? data_get($payload, 'receipt_id'), 80);
        $traceId = $this->nullableString($context['trace_id'] ?? data_get($payload, 'trace_id'), 80);
        $correlationId = $this->string($context['correlation_id'] ?? data_get($payload, 'correlation_id', data_get($payload, 'envelope_id', (string) Str::ulid())), 120);
        $causationId = $this->nullableString($context['causation_id'] ?? null, 80);
        $scopeType = $this->nullableString($context['scope_type'] ?? data_get($payload, 'scope_type'), 40);
        $scopeId = $this->nullableString($context['scope_id'] ?? data_get($payload, 'scope_id'), 80);
        $payloadHash = $this->payloadHash($payload);

        $hasScopeType = $this->columnAvailable('scope_type');
        $hasScopeId = $this->columnAvailable('scope_id');
        $hasEventHash = $this->columnAvailable('event_hash');
        $hasPrevEventHash = $this->columnAvailable('prev_event_hash');
        $hasChainBasis = $this->columnAvailable('chain_basis');
        $hasChainKeyHash = $this->columnAvailable('chain_key_hash');
        $hasChainPosition = $this->columnAvailable('chain_position');
        $writesV2 = $hasEventHash
            && $hasPrevEventHash
            && $hasChainBasis
            && $hasChainKeyHash
            && $hasChainPosition;

        $row = [
            'event_id' => $eventId,
            'schema_version' => $writesV2 ? self::SCHEMA_VERSION_V2 : self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'trace_id' => $traceId,
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'event_type' => $type->value,
            'emitter_stage' => $this->string($context['emitter_stage'] ?? 'atlas.kernel', 120),
            'emitter_version' => $this->string($context['emitter_version'] ?? 'v1', 80),
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'occurred_at' => $occurredAt,
        ];

        if ($hasScopeType) {
            $row['scope_type'] = $scopeType;
        }
        if ($hasScopeId) {
            $row['scope_id'] = $scopeId;
        }
        if ($hasPrevEventHash) {
            $row['prev_event_hash'] = null;
        }
        if ($hasChainBasis) {
            $row['chain_basis'] = $writesV2
                ? self::CHAIN_BASIS_FULL_ENVELOPE_V2
                : self::CHAIN_BASIS_HASH_CHAINED;
        }
        if ($writesV2) {
            $row['chain_key_hash'] = self::chainKeyHash($scopeType, $scopeId, $correlationId);
            $row['chain_position'] = null;
        }

        $write = function () use (
            $row,
            $hasEventHash,
            $hasPrevEventHash,
            $writesV2,
            $eventId,
            $tenantId,
            $type,
            $envelopeId,
            $correlationId,
            $causationId,
            $scopeType,
            $scopeId,
            $payloadHash,
            $occurredAt,
        ): AtlasLedgerEvent {
            if ($writesV2) {
                $chainKeyHash = (string) $row['chain_key_hash'];
                $this->serializeChainHead($tenantId, $chainKeyHash);
                $previous = $this->previousV2EventForChain($tenantId, $chainKeyHash);
                $row['prev_event_hash'] = $previous?->event_hash;
                $row['chain_position'] = $previous === null
                    ? 1
                    : ((int) $previous->chain_position + 1);
                $row['event_hash'] = self::computeV2EnvelopeHash(self::fullEnvelopeHashBasis($row));
            } elseif ($hasPrevEventHash) {
                $row['prev_event_hash'] = $this->previousEventHashForChain($tenantId, $scopeType, $scopeId, $correlationId);
            }
            if ($hasEventHash && ! $writesV2) {
                $row['event_hash'] = self::computeEventHash([
                    'event_id' => $eventId,
                    'event_type' => $type->value,
                    'envelope_id' => $envelopeId,
                    'correlation_id' => $correlationId,
                    'causation_id' => $causationId,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'prev_event_hash' => $row['prev_event_hash'] ?? null,
                    'payload_hash' => $payloadHash,
                    'occurred_at' => $occurredAt->toISOString(),
                ]);
            }

            return $this->ledgerQuery()->create($row);
        };

        if ($writesV2 || ($hasPrevEventHash && $hasEventHash)) {
            return $this->ledgerConnection()->transaction($write);
        }

        if ($hasEventHash) {
            $row['event_hash'] = self::computeEventHash([
                'event_id' => $eventId,
                'event_type' => $type->value,
                'envelope_id' => $envelopeId,
                'correlation_id' => $correlationId,
                'causation_id' => $causationId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'prev_event_hash' => $row['prev_event_hash'] ?? null,
                'payload_hash' => $payloadHash,
                'occurred_at' => $occurredAt->toISOString(),
            ]);
        }

        return $this->ledgerQuery()->create($row);
    }

    /**
     * Deterministic SHA-256 over the canonical envelope of a ledger event.
     * Same input always produces the same hash. Null fields are dropped
     * before hashing so callers without scope context produce stable
     * hashes that downstream consumers can rely on.
     *
     * @param  array<string,mixed>  $envelope
     */
    public static function computeEventHash(array $envelope): string
    {
        $filtered = array_filter(
            $envelope,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        return hash('sha256', self::canonicalJson($filtered));
    }

    /**
     * V2 seals every field in the full envelope, including the distinction
     * between absent, null, and empty values. Legacy V1 hashing deliberately
     * retains its historical omission behaviour in computeEventHash().
     *
     * @param  array<string,mixed>  $envelope
     */
    public static function computeV2EnvelopeHash(array $envelope): string
    {
        return hash('sha256', self::canonicalJson($envelope));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalizeHashValue($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function chainKeyHash(?string $scopeType, ?string $scopeId, string $correlationId): string
    {
        $basis = $scopeType !== null && $scopeId !== null
            ? ['kind' => 'scope', 'scope_type' => $scopeType, 'scope_id' => $scopeId]
            : ['kind' => 'correlation', 'correlation_id' => $correlationId];

        return self::computeEventHash($basis);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function fullEnvelopeHashBasis(array $row): array
    {
        $occurredAt = $row['occurred_at'] ?? null;
        if ($occurredAt instanceof \DateTimeInterface) {
            $occurredAt = CarbonImmutable::instance($occurredAt)->utc()->format('Y-m-d\\TH:i:s\\Z');
        } elseif (is_string($occurredAt) && trim($occurredAt) !== '') {
            try {
                $occurredAt = CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d\\TH:i:s\\Z');
            } catch (Throwable) {
                $occurredAt = trim($occurredAt);
            }
        }

        return [
            'schema_version' => $row['schema_version'] ?? null,
            'event_id' => $row['event_id'] ?? null,
            'tenant_id' => $row['tenant_id'] ?? null,
            'operator_id' => $row['operator_id'] ?? null,
            'envelope_id' => $row['envelope_id'] ?? null,
            'receipt_id' => $row['receipt_id'] ?? null,
            'trace_id' => $row['trace_id'] ?? null,
            'correlation_id' => $row['correlation_id'] ?? null,
            'causation_id' => $row['causation_id'] ?? null,
            'event_type' => $row['event_type'] ?? null,
            'emitter_stage' => $row['emitter_stage'] ?? null,
            'emitter_version' => $row['emitter_version'] ?? null,
            'scope_type' => $row['scope_type'] ?? null,
            'scope_id' => $row['scope_id'] ?? null,
            'chain_basis' => $row['chain_basis'] ?? null,
            'chain_key_hash' => $row['chain_key_hash'] ?? null,
            'chain_position' => isset($row['chain_position']) ? (int) $row['chain_position'] : null,
            'prev_event_hash' => $row['prev_event_hash'] ?? null,
            'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : null,
            'payload_hash' => $row['payload_hash'] ?? null,
            'occurred_at' => $occurredAt,
        ];
    }

    private function serializeChainHead(string $tenantId, string $chainKeyHash): void
    {
        $connection = $this->ledgerConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$tenantId, $chainKeyHash]);
    }

    private function previousV2EventForChain(string $tenantId, string $chainKeyHash): ?AtlasLedgerEvent
    {
        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('chain_key_hash', $chainKeyHash)
            ->where('schema_version', self::SCHEMA_VERSION_V2)
            ->orderByDesc('chain_position')
            ->first();
    }

    private function previousEventHashForChain(
        string $tenantId,
        ?string $scopeType,
        ?string $scopeId,
        string $correlationId,
    ): ?string {
        $query = $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('event_hash')
            ->where('event_hash', '<>', '')
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_id')
            ->lockForUpdate();

        if ($this->columnAvailable('chain_basis')) {
            $query->where('chain_basis', self::CHAIN_BASIS_HASH_CHAINED);
        }

        if ($scopeType !== null
            && $scopeId !== null
            && $this->columnAvailable('scope_type')
            && $this->columnAvailable('scope_id')) {
            $query->where('scope_type', $scopeType)->where('scope_id', $scopeId);
        } else {
            $query->where('correlation_id', $correlationId);
        }

        $previous = $query->first();
        $hash = $previous?->getAttribute('event_hash');

        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForScope(
        string $scopeType,
        string $scopeId,
        int $limit = 100,
        ?string $tenantId = null,
    ): array {
        $tenantId = $this->proofTenant($tenantId);
        if ($tenantId === null
            || ! $this->tableAvailable()
            || ! $this->columnAvailable('scope_type')
            || ! $this->columnAvailable('scope_id')) {
            return [];
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $event->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForCorrelation(string $correlationId, int $limit = 100, ?string $tenantId = null): array
    {
        $tenantId = $this->proofTenant($tenantId);
        if ($tenantId === null || ! $this->tableAvailable()) {
            return [];
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('correlation_id', $correlationId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $event->toArray())
            ->values()
            ->all();
    }

    public function eventById(string $eventId, ?string $tenantId = null): ?AtlasLedgerEvent
    {
        $tenantId = $this->proofTenant($tenantId);
        if (! $this->tableAvailable()) {
            return null;
        }

        if ($tenantId === null) {
            $tenantIds = $this->ledgerQuery()->whereKey($eventId)->distinct()->pluck('tenant_id')->filter()->values();
            if ($tenantIds->count() !== 1) {
                return null;
            }
            $tenantId = (string) $tenantIds->first();
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->whereKey($eventId)
            ->first();
    }

    public function latestForCorrelation(
        string $correlationId,
        ?string $eventName = null,
        ?string $tenantId = null,
    ): ?AtlasLedgerEvent {
        $tenantId = $this->proofTenant($tenantId);
        if (! $this->tableAvailable()) {
            return null;
        }

        if ($tenantId === null) {
            $tenantIds = $this->ledgerQuery()
                ->where('correlation_id', $correlationId)
                ->when($eventName !== null, fn ($query) => $query->where('payload->event_name', $eventName))
                ->distinct()
                ->pluck('tenant_id')
                ->filter()
                ->values();
            if ($tenantIds->count() !== 1) {
                return null;
            }
            $tenantId = (string) $tenantIds->first();
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('correlation_id', $correlationId)
            ->when($eventName !== null, fn ($query) => $query->where('payload->event_name', $eventName))
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_id')
            ->first();
    }

    public function latestForScope(
        string $scopeType,
        string $scopeId,
        ?string $eventName = null,
        ?string $tenantId = null,
    ): ?AtlasLedgerEvent {
        $tenantId = $this->proofTenant($tenantId);
        if ($tenantId === null
            || ! $this->tableAvailable()
            || ! $this->columnAvailable('scope_type')
            || ! $this->columnAvailable('scope_id')) {
            return null;
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->when($eventName !== null, fn ($query) => $query->where('payload->event_name', $eventName))
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_id')
            ->first();
    }

    public function engineeringOutcomeEvent(
        string $deliveryId,
        string $orderHash,
        string $outcomeHash,
        ?string $tenantId = null,
    ): ?AtlasLedgerEvent
    {
        $tenantId = $this->proofTenant($tenantId);
        if ($tenantId === null
            || ! $this->tableAvailable()
            || ! $this->columnAvailable('scope_type')
            || ! $this->columnAvailable('scope_id')) {
            return null;
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('scope_type', 'engineering_delivery')
            ->where('scope_id', $deliveryId)
            ->where('payload->event_name', 'engineering.outcome.recorded')
            ->where('payload->order_hash', $orderHash)
            ->where('payload->outcome->outcome_hash', $outcomeHash)
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_id')
            ->first();
    }

    public function eventIntegrityValid(AtlasLedgerEvent $event): bool
    {
        return $this->eventIntegrityStatus($event) === 'verified';
    }

    public function eventIntegrityStatus(AtlasLedgerEvent $event): string
    {
        $rawPayload = $event->payload;
        if (! is_array($rawPayload)) {
            return 'payload_invalid';
        }
        $payload = $this->canonicalize($rawPayload);
        if (! hash_equals((string) $event->payload_hash, $this->payloadHash($payload))) {
            return 'payload_hash_mismatch';
        }

        if ((string) $event->schema_version !== self::SCHEMA_VERSION_V2) {
            return self::INTEGRITY_LEGACY_UNVERIFIED;
        }

        if ((string) $event->chain_basis !== self::CHAIN_BASIS_FULL_ENVELOPE_V2
            || preg_match('/^[a-f0-9]{64}$/', (string) $event->chain_key_hash) !== 1
            || (int) $event->chain_position < 1
            || preg_match('/^[a-f0-9]{64}$/', (string) $event->event_hash) !== 1) {
            return 'v2_envelope_invalid';
        }

        $expected = self::computeV2EnvelopeHash(self::fullEnvelopeHashBasis(array_merge(
            $event->getAttributes(),
            ['payload' => $payload],
        )));

        return hash_equals($expected, (string) $event->event_hash)
            ? 'verified'
            : 'event_hash_mismatch';
    }

    public function recordEnvelopeCreated(OperationEnvelope $envelope): ?AtlasLedgerEvent
    {
        return $this->record(LedgerEventType::EnvelopeCreated, [
            'envelope_id' => $envelope->envelopeId,
            'parent_envelope_id' => $envelope->parentEnvelopeId,
            'schema_version' => $envelope->schemaVersion,
            'trace_id' => $envelope->audit->traceId,
            'chain_hash' => $envelope->audit->chainHash,
            'input_hash' => $envelope->input->inputHash,
            'operator' => [
                'tenant_id' => $envelope->operator->tenantId,
                'operator_id' => $envelope->operator->operatorId,
            ],
            'origin' => [
                'surface_id' => $envelope->origin->surfaceId,
                'surface_version' => $envelope->origin->surfaceVersion,
                'session_id' => $envelope->origin->sessionId,
            ],
        ], [
            'tenant_id' => $envelope->operator->tenantId,
            'operator_id' => $envelope->operator->operatorId,
            'envelope_id' => $envelope->envelopeId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.envelope_factory',
            'emitter_version' => OperationEnvelope::SCHEMA_VERSION,
        ]);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context
     * @return array{envelope_created:null,decision_issued:?AtlasLedgerEvent}
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

        $decisionEvent = $this->record(LedgerEventType::DecisionIssued, [
            'envelope_id' => $receipt['envelope_id'] ?? null,
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'schema_version' => $receipt['schema_version'] ?? null,
            'dry_run' => (bool) ($receipt['dry_run'] ?? false),
            'signed_by' => $receipt['signed_by'] ?? null,
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
            'parent_receipt_id' => $receipt['parent_receipt_id'] ?? null,
            'parent_chain_hash' => $receipt['parent_chain_hash'] ?? data_get($receipt, 'metadata.parent_chain_hash'),
            'chain_hash' => $receipt['chain_hash'] ?? null,
            'issued_at' => $receipt['issued_at'] ?? null,
            'expires_at' => $receipt['expires_at'] ?? null,
            'metadata' => is_array($receipt['metadata'] ?? null) ? $receipt['metadata'] : [],
        ], $ledgerContext);

        return [
            'envelope_created' => null,
            'decision_issued' => $decisionEvent,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     */
    public function recordKernelPipelineAccepted(array $plan, array $context = []): ?AtlasLedgerEvent
    {
        return $this->recordKernelPipelineContract(
            type: LedgerEventType::KernelPipelineAccepted,
            plan: $plan,
            status: 'accepted',
            violations: [],
            context: $context,
        );
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $violations
     * @param  array<string,mixed>  $context
     */
    public function recordKernelPipelineRejected(array $plan, array $violations, array $context = []): ?AtlasLedgerEvent
    {
        return $this->recordKernelPipelineContract(
            type: LedgerEventType::KernelPipelineRejected,
            plan: $plan,
            status: 'rejected',
            violations: $violations,
            context: $context,
        );
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $violations
     * @param  array<string,mixed>  $context
     */
    private function recordKernelPipelineContract(
        LedgerEventType $type,
        array $plan,
        string $status,
        array $violations,
        array $context = [],
    ): ?AtlasLedgerEvent {
        $pipelineId = $this->string($plan['pipeline_id'] ?? data_get($context, 'pipeline_id', 'kernel_pipeline_unknown'), 120);
        $surfaceId = $this->nullableString(data_get($plan, 'input.surface_id') ?? data_get($plan, 'surface_binding.surface'), 120);
        $flow = $this->nullableString(data_get($plan, 'input.safe_hints.flow') ?? data_get($plan, 'surface_binding.flow'), 120);
        $stageOrder = $this->kernelPipelineStageOrder($plan);

        return $this->record($type, [
            'status' => $status,
            'violations' => array_values(array_map('strval', $violations)),
            'pipeline' => [
                'pipeline_id' => $pipelineId,
                'schema_version' => $plan['schema_version'] ?? null,
                'mode' => $plan['mode'] ?? null,
                'status' => $plan['status'] ?? null,
                'canonical_flow_hash' => $plan['canonical_flow_hash'] ?? null,
                'stage_order' => $stageOrder,
                'stage_count' => $plan['stage_count'] ?? count($stageOrder),
                'provider_execution_allowed' => (bool) ($plan['provider_execution_allowed'] ?? false),
                'runtime_execution_allowed' => (bool) ($plan['runtime_execution_allowed'] ?? false),
                'execution_guards' => is_array($plan['execution_guards'] ?? null) ? $plan['execution_guards'] : [],
            ],
            'surface' => [
                'surface_id' => $surfaceId,
                'binding_surface' => data_get($plan, 'surface_binding.surface'),
                'command' => data_get($plan, 'surface_binding.command'),
                'input_mode' => data_get($plan, 'surface_binding.input_mode'),
            ],
            'surface_contract' => [
                'required' => (bool) data_get($context, 'surface_contract.required', false),
                'source' => $this->nullableString(data_get($context, 'surface_contract.source'), 120),
                'surface_must_not_decide' => (bool) data_get($context, 'surface_contract.surface_must_not_decide', false),
                'provider_execution_blocked_until_runtime_migration' => (bool) data_get($context, 'surface_contract.provider_execution_blocked_until_runtime_migration', false),
                'runtime_execution_blocked_until_runtime_migration' => (bool) data_get($context, 'surface_contract.runtime_execution_blocked_until_runtime_migration', false),
            ],
            'routing' => [
                'domain' => data_get($plan, 'input.safe_hints.domain'),
                'flow' => $flow,
                'mode' => data_get($plan, 'input.safe_hints.mode'),
                'runtime' => data_get($plan, 'input.safe_hints.runtime'),
            ],
            'input' => [
                'primary_text_hash' => data_get($plan, 'input.primary_text_hash'),
                'input_fingerprint' => data_get($plan, 'input.input_fingerprint'),
                'hints_hash' => data_get($plan, 'input.hints_hash'),
                'metadata_keys' => data_get($plan, 'input.metadata_keys', []),
            ],
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($plan, 'input.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($plan, 'input.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? 'kernel_pipeline:'.$pipelineId,
            'receipt_id' => $context['receipt_id'] ?? null,
            'trace_id' => $context['trace_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? $pipelineId,
            'causation_id' => $context['causation_id'] ?? null,
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.kernel_pipeline_guard',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.kernel_pipeline_guard.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<int,string>
     */
    private function kernelPipelineStageOrder(array $plan): array
    {
        if (is_array($plan['stage_order'] ?? null)) {
            return AiStringListNormalizer::truthyTrimmedScalarValues($plan['stage_order']);
        }

        return AiStringListNormalizer::truthyMappedScalarStrings(
            (array) ($plan['stages'] ?? $plan['stage_results'] ?? []),
            static fn (mixed $stage): mixed => data_get($stage, 'stage'),
        );
    }

    /**
     * @param  Throwable|array<string,mixed>|string  $failure
     * @param  array<string,mixed>  $context
     * @return array{classification:array<string,mixed>,handling:array<string,mixed>,event:?AtlasLedgerEvent}
     */
    public function recordFailure(Throwable|array|string $failure, array $context = []): array
    {
        $message = is_string($context['message'] ?? null) ? $context['message'] : null;
        $classification = $this->failureClassifier->classify($failure, $message);
        $classificationPayload = $classification->toArray();
        $handling = $this->failureHandlers
            ->handlerFor($classification->domain)
            ->handle($classificationPayload, $context);

        $eventType = match (true) {
            (bool) ($handling['requires_human_review'] ?? false) => LedgerEventType::OperationNeedsReview,
            (bool) ($handling['retryable'] ?? false) => LedgerEventType::OperationFailed,
            default => LedgerEventType::OperationBlocked,
        };

        $event = $this->record($eventType, [
            'classification' => $classificationPayload,
            'handling' => $handling,
            'failure' => $this->failurePayload($failure, $message),
        ], $context + [
            'emitter_stage' => 'atlas.failure_classifier',
            'emitter_version' => 'atlas.failure.v1',
        ]);

        return [
            'classification' => $classificationPayload,
            'handling' => $handling,
            'event' => $event,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $context
     */
    public function recordPolicyContractBlocked(
        string $contract,
        array $receipt,
        array $options = [],
        array $context = [],
    ): ?AtlasLedgerEvent {
        $status = $this->string($receipt['status'] ?? 'unknown', 120);
        $payload = [
            'policy_contract' => $contract,
            'status' => $status,
            'violation_code' => $contract.'.'.$status,
            'receipt' => $receipt,
            'surface_id' => data_get($options, 'payload.surface_id', data_get($options, 'source')),
            'provider' => $options['provider'] ?? data_get($options, 'payload.selected_provider'),
            'model' => $options['model'] ?? null,
            'client_id' => $options['client_id'] ?? null,
            'session_id' => data_get($options, 'payload.session_id'),
            'thread_id' => data_get($options, 'payload.thread_id'),
        ];

        return $this->record(LedgerEventType::OperationBlocked, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($options, 'payload.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($options, 'payload.operator_id', 'system'),
            'envelope_id' => $context['envelope_id']
                ?? data_get($options, 'payload.decision_receipt.envelope_id')
                ?? data_get($options, 'payload.decision_receipt.receipt_v2.envelope_id')
                ?? 'policy_contract_pre_trace',
            'receipt_id' => $context['receipt_id']
                ?? data_get($options, 'payload.decision_receipt.receipt_id')
                ?? data_get($options, 'payload.decision_receipt.receipt_v2.receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($options, 'payload.trace_id'),
            'correlation_id' => $context['correlation_id']
                ?? $options['client_id']
                ?? data_get($options, 'payload.trace_id')
                ?? data_get($options, 'payload.decision_receipt.envelope_id')
                ?? 'policy_contract_pre_trace',
            'emitter_stage' => 'atlas.policy_contract',
            'emitter_version' => 'atlas.policy_contract.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $context
     */
    public function recordProviderMemoryBlocked(
        AtlasMemoryEntry $entry,
        array $decision,
        string $target,
        array $context = [],
    ): ?AtlasLedgerEvent {
        $payload = [
            'policy_contract' => 'memory.provider_projection',
            'status' => 'provider_memory_blocked',
            'violation_code' => 'memory.provider_projection.'.($decision['reason'] ?? 'not_provider_safe'),
            'target' => $target,
            'memory_entry' => [
                'id' => $entry->id ? (string) $entry->id : null,
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
            ],
            'privacy' => [
                'class' => $decision['privacy_class'] ?? null,
                'external_ai_allowed' => $decision['external_ai_allowed'] ?? null,
                'metadata_external_ai_allowed' => $decision['metadata_external_ai_allowed'] ?? null,
                'reason' => $decision['reason'] ?? 'not_provider_safe',
            ],
        ];

        return $this->record(LedgerEventType::OperationBlocked, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($entry->metadata, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($entry->metadata, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($entry->metadata, 'envelope_id', 'memory_provider_projection'),
            'receipt_id' => $context['receipt_id'] ?? data_get($entry->metadata, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? $entry->trace_id,
            'correlation_id' => $context['correlation_id'] ?? $entry->trace_id ?? $entry->session_id ?? 'memory_provider_projection',
            'emitter_stage' => 'atlas.memory_provider_privacy',
            'emitter_version' => 'atlas.memory_provider_privacy.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordRepairDecision(RepairDecision $decision, array $context = []): ?AtlasLedgerEvent
    {
        $payload = $decision->evidencePayload + [
            'decision' => $decision->toArray(),
            'repair_executed' => false,
        ];

        return $this->record(LedgerEventType::RepairInitiated, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'repair_decision'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id'] ?? data_get($payload, 'envelope_id', 'repair_decision'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.repair',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.repair.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordRepairResult(RepairResult $result, array $context = []): ?AtlasLedgerEvent
    {
        $payload = $result->evidencePayload + [
            'request' => $result->request->toArray(),
            'decision' => $result->decision->toArray(),
            'attempt' => $result->attempt?->toArray(),
            'repair_executed' => $result->executed,
        ];

        return $this->record(LedgerEventType::RepairCompleted, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'repair_result'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id'] ?? data_get($payload, 'envelope_id', 'repair_result'),
            'causation_id' => $context['causation_id'] ?? data_get($result->decision->evidencePayload, 'decision_hash'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.repair',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.repair.v1',
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<string,mixed>  $context
     */
    public function recordAgentBehaviorGateEvaluation(
        AiTrace $trace,
        AiQualityEvaluation $evaluation,
        array $findings,
        array $context = [],
    ): ?AtlasLedgerEvent {
        $traceMetadata = is_array($trace->metadata) ? $trace->metadata : [];
        $evaluationMetadata = is_array($evaluation->metadata) ? $evaluation->metadata : [];

        return $this->record(LedgerEventType::GateEvaluated, [
            'gate_id' => 'atlas.agent_behavior',
            'schema_version' => 'atlas.agent_behavior.gate_evaluation.v1',
            'status' => $evaluation->status,
            'score' => $evaluation->score,
            'provider' => $trace->provider,
            'model' => $trace->model,
            'agent_slug' => $trace->agent_slug,
            'flags' => collect($evaluation->flags ?? [])->pluck('code')->filter()->values()->all(),
            'suggested_actions' => collect($evaluation->suggested_actions ?? [])->pluck('code')->filter()->values()->all(),
            'agent_behavior_findings' => array_values($findings),
            'contract_id' => data_get($findings, '0.metadata.contract_id'),
            'contract_hash' => data_get($findings, '0.metadata.contract_hash'),
            'quality_evaluation_id' => (string) $evaluation->id,
            'trace' => [
                'trace_id' => (string) $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'source_type' => $trace->source_type,
            ],
            'evidence' => [
                'task_looks_like_development' => (bool) data_get($evaluationMetadata, 'evidence.task_looks_like_development', false),
                'response_chars' => data_get($evaluationMetadata, 'evidence.response_chars'),
                'code_fence_count' => data_get($evaluationMetadata, 'evidence.code_fence_count'),
                'code_like_line_count' => data_get($evaluationMetadata, 'evidence.code_like_line_count'),
            ],
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($traceMetadata, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($traceMetadata, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id']
                ?? data_get($traceMetadata, 'decision_receipt.envelope_id')
                ?? data_get($traceMetadata, 'decision_receipt.receipt_v2.envelope_id')
                ?? 'ai_trace:'.(string) $trace->id,
            'receipt_id' => $context['receipt_id']
                ?? data_get($traceMetadata, 'decision_receipt.receipt_id')
                ?? data_get($traceMetadata, 'decision_receipt.receipt_v2.receipt_id'),
            'trace_id' => $context['trace_id'] ?? (string) $trace->id,
            'correlation_id' => $context['correlation_id'] ?? (string) ($trace->thread_id ?: $trace->id),
            'causation_id' => $context['causation_id'] ?? (string) $evaluation->id,
            'emitter_stage' => 'atlas.agent_behavior_quality_gate',
            'emitter_version' => 'atlas.agent_behavior.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordSloObservation(KernelSloAssessment $assessment, array $context = []): ?AtlasLedgerEvent
    {
        return $this->record(LedgerEventType::SloObserved, [
            'dimensions' => $this->sloDimensions($context),
            'slo' => $assessment->toArray(),
            'stage' => $assessment->stage,
            'status' => $assessment->status,
            'severity' => $assessment->severity,
            'violations' => $assessment->violations,
        ], [
            'tenant_id' => $context['tenant_id'] ?? 'default',
            'operator_id' => $context['operator_id'] ?? 'system',
            'envelope_id' => $context['envelope_id'] ?? 'slo_observation',
            'receipt_id' => $context['receipt_id'] ?? null,
            'trace_id' => $context['trace_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? $context['trace_id'] ?? 'slo_observation',
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function recordVoiceEvent(
        LedgerEventType $type,
        array $payload,
        array $context = [],
    ): ?AtlasLedgerEvent {
        if (! str_starts_with($type->value, 'VOICE_')) {
            throw new \InvalidArgumentException('recordVoiceEvent only accepts VOICE_* ledger event types.');
        }

        $payload = $this->sanitizeVoicePayload($payload);

        return $this->record($type, [
            'schema_version' => 'atlas.voice.ledger_event.v1',
            'privacy_class' => $payload['privacy_class'] ?? 'p3_audio',
            'surface_id' => $payload['surface_id'] ?? 'voice_realtime',
            'voice' => $payload,
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'voice_realtime'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id']
                ?? data_get($payload, 'session_id')
                ?? data_get($payload, 'turn_id')
                ?? data_get($payload, 'envelope_id')
                ?? 'voice_realtime',
            'causation_id' => $context['causation_id'] ?? data_get($payload, 'causation_id'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.voice_realtime',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.voice.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function recordLocalRagEvent(
        LedgerEventType $type,
        array $payload,
        array $context = [],
    ): ?AtlasLedgerEvent {
        if (! str_starts_with($type->value, 'LOCAL_RAG_')) {
            throw new \InvalidArgumentException('recordLocalRagEvent only accepts LOCAL_RAG_* ledger event types.');
        }

        $payload = $this->sanitizeLocalRagPayload($payload);

        return $this->record($type, [
            'schema_version' => 'atlas.local_rag.ledger_event.v1',
            'privacy_class' => $payload['privacy_class'] ?? 'internal',
            'runtime_family' => $payload['runtime_family'] ?? 'laravel_kernel_now_python_ai_data_future',
            'local_rag' => $payload,
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'local_rag'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id']
                ?? data_get($payload, 'benchmark_id')
                ?? data_get($payload, 'query_hash')
                ?? data_get($payload, 'envelope_id')
                ?? 'local_rag',
            'causation_id' => $context['causation_id'] ?? data_get($payload, 'causation_id'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.context_retrieval',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.local_rag.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizeVoicePayload(array $payload): array
    {
        $forbiddenKeys = [
            'audio',
            'audio_bytes',
            'audio_blob',
            'audio_buffer',
            'audio_raw',
            'raw_audio',
            'raw_audio_bytes',
            'pcm',
            'wav',
            'access_token',
            'livekit_token',
            'token',
            'api_key',
            'api_secret',
        ];

        foreach ($forbiddenKeys as $key) {
            unset($payload[$key]);
        }

        if (isset($payload['transcript']) && is_string($payload['transcript'])) {
            $payload['transcript_hash'] ??= hash('sha256', $payload['transcript']);
            $payload['transcript_length'] ??= mb_strlen($payload['transcript']);
            unset($payload['transcript']);
        }

        foreach (['response_text', 'synthesized_text', 'tts_text'] as $textKey) {
            if (isset($payload[$textKey]) && is_string($payload[$textKey])) {
                $hashKey = $textKey.'_hash';
                $lengthKey = $textKey.'_length';
                $payload[$hashKey] ??= hash('sha256', $payload[$textKey]);
                $payload[$lengthKey] ??= mb_strlen($payload[$textKey]);
                unset($payload[$textKey]);
            }
        }

        if (isset($payload['audio_hash']) && is_scalar($payload['audio_hash'])) {
            $payload['audio_hash'] = $this->string((string) $payload['audio_hash'], 120);
        }

        return $this->removeVoiceSecrets($payload, $forbiddenKeys);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizeLocalRagPayload(array $payload): array
    {
        $forbiddenKeys = [
            'api_key',
            'api_secret',
            'content',
            'context',
            'documents',
            'excerpt',
            'excerpts',
            'raw_context',
            'raw_documents',
            'raw_query',
            'secret',
            'secrets',
            'token',
        ];

        foreach (['query', 'prompt', 'input_text'] as $textKey) {
            if (isset($payload[$textKey]) && is_string($payload[$textKey])) {
                $payload[$textKey.'_hash'] ??= hash('sha256', $payload[$textKey]);
                $payload[$textKey.'_length'] ??= mb_strlen($payload[$textKey]);
                unset($payload[$textKey]);
            }
        }

        return $this->removeForbiddenKeys($payload, $forbiddenKeys);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $forbiddenKeys
     * @return array<string,mixed>
     */
    private function removeVoiceSecrets(array $payload, array $forbiddenKeys): array
    {
        return $this->removeForbiddenKeys($payload, $forbiddenKeys);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $forbiddenKeys
     * @return array<string,mixed>
     */
    private function removeForbiddenKeys(array $payload, array $forbiddenKeys): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, $forbiddenKeys, true)) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->removeForbiddenKeys($value, $forbiddenKeys);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,string>
     */
    private function sloDimensions(array $context): array
    {
        $aliases = [
            'domain' => ['domain', 'kernel_domain'],
            'flow' => ['flow', 'kernel_flow'],
            'surface_id' => ['surface_id', 'surface', 'app_surface'],
            'provider' => ['provider', 'selected_provider', 'provider_id'],
            'model' => ['model', 'selected_model'],
            'runtime' => ['runtime', 'runtime_id'],
            'tool_id' => ['tool_id', 'tool'],
            'job_id' => ['job_id'],
            'attempt_id' => ['attempt_id'],
            'worker_id' => ['worker_id'],
            'rivals_arm' => ['rivals_arm'],
        ];

        $dimensions = [];
        foreach ($aliases as $target => $keys) {
            foreach ($keys as $key) {
                $value = data_get($context, $key);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $dimensions[$target] = $this->string($value, 120);
                    break;
                }
            }
        }

        return $dimensions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForEnvelope(string $envelopeId, ?string $tenantId = null): array
    {
        $tenantId = $this->proofTenant($tenantId);
        if ($tenantId === null || ! $this->tableAvailable()) {
            return [];
        }

        return $this->ledgerQuery()
            ->where('tenant_id', $tenantId)
            ->where('envelope_id', $envelopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $event->toArray())
            ->values()
            ->all();
    }

    private function tableAvailable(): bool
    {
        if (! $this->roleEnforcementEnabled()) {
            return DatabaseTableAvailability::has('atlas_ledger_events');
        }

        return $this->ledgerConnection()->getSchemaBuilder()->hasTable('atlas_ledger_events');
    }

    private function columnAvailable(string $column): bool
    {
        if (! $this->roleEnforcementEnabled()) {
            return DatabaseTableAvailability::hasColumn('atlas_ledger_events', $column);
        }

        return $this->ledgerConnection()->getSchemaBuilder()->hasColumn('atlas_ledger_events', $column);
    }

    private function ledgerQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $connectionName = $this->runtimeConnectionName();

        return $connectionName === null
            ? AtlasLedgerEvent::query()
            : AtlasLedgerEvent::on($connectionName);
    }

    private function ledgerConnection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection($this->runtimeConnectionName());
    }

    private function runtimeConnectionName(): ?string
    {
        if (! $this->roleEnforcementEnabled()) {
            return null;
        }

        $connectionName = trim((string) config('database.ledger_roles.runtime_connection', ''));
        $connection = config('database.connections.'.$connectionName);
        if ($connectionName === ''
            || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'pgsql'
            || trim((string) ($connection['username'] ?? '')) === '') {
            throw new \LogicException('atlas_ledger_runtime_role_configuration_invalid');
        }

        return $connectionName;
    }

    private function roleEnforcementEnabled(): bool
    {
        return filter_var(config('database.ledger_roles.enforced', false), FILTER_VALIDATE_BOOL);
    }

    private function proofTenant(?string $tenantId): ?string
    {
        $tenantId = trim((string) $tenantId);

        return $tenantId !== '' ? $tenantId : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalize(array $payload): array
    {
        /** @var array<string,mixed> $canonical */
        $canonical = self::canonicalizeHashValue($payload);

        return $canonical;
    }

    private static function canonicalizeHashValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeHashValue($item);
        }

        return $isList ? array_values($value) : $value;
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

    /**
     * @param  Throwable|array<string,mixed>|string  $failure
     * @return array<string,mixed>
     */
    private function failurePayload(Throwable|array|string $failure, ?string $message): array
    {
        if ($failure instanceof Throwable) {
            return [
                'source' => 'throwable',
                'class' => $failure::class,
                'code' => $failure->getCode(),
                'message' => $failure->getMessage(),
            ];
        }

        if (is_array($failure)) {
            return [
                'source' => 'payload',
                'payload' => $failure,
            ];
        }

        return [
            'source' => 'status_message',
            'status' => $failure,
            'message' => $message,
        ];
    }
}

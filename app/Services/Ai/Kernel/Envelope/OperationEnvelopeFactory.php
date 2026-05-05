<?php

namespace App\Services\Ai\Kernel\Envelope;

use Illuminate\Support\Str;

class OperationEnvelopeFactory
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(array $payload): OperationEnvelope
    {
        $operator = OperatorContext::fromArray(is_array($payload['operator'] ?? null) ? $payload['operator'] : $payload);
        $origin = Provenance::fromArray(is_array($payload['origin'] ?? null) ? $payload['origin'] : $payload);
        $input = KernelInput::fromArray(is_array($payload['input'] ?? null) ? $payload['input'] : $payload);
        $envelopeId = (string) Str::ulid();
        $parentEnvelopeId = $this->optionalString($payload['parent_envelope_id'] ?? null);
        $parentChainHash = $this->optionalString($payload['parent_chain_hash'] ?? null);
        $chainHash = $this->chainHash($parentChainHash, [
            'envelope_id' => $envelopeId,
            'parent_envelope_id' => $parentEnvelopeId,
            'schema_version' => OperationEnvelope::SCHEMA_VERSION,
            'operator_id' => $operator->operatorId,
            'tenant_id' => $operator->tenantId,
            'surface_id' => $origin->surfaceId,
            'session_id' => $origin->sessionId,
            'input_hash' => $input->inputHash,
        ]);

        return new OperationEnvelope(
            envelopeId: $envelopeId,
            parentEnvelopeId: $parentEnvelopeId,
            schemaVersion: OperationEnvelope::SCHEMA_VERSION,
            operator: $operator,
            origin: $origin,
            input: $input,
            routing: new RoutingState(),
            decision: null,
            execution: new ExecutionState(),
            output: null,
            audit: new AuditState(
                traceId: (string) ($payload['trace_id'] ?? $envelopeId),
                parentTraceId: $this->optionalString($payload['parent_trace_id'] ?? null),
                chainHash: $chainHash,
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function chainHash(?string $parentChainHash, array $payload): string
    {
        ksort($payload);

        return hash('sha256', ($parentChainHash ?? '').json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function optionalString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}

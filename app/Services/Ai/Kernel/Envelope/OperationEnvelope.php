<?php

namespace App\Services\Ai\Kernel\Envelope;

final class OperationEnvelope
{
    public const SCHEMA_VERSION = 'atlas.envelope.v1';

    public function __construct(
        public readonly string $envelopeId,
        public readonly ?string $parentEnvelopeId,
        public readonly string $schemaVersion,
        public readonly OperatorContext $operator,
        public readonly Provenance $origin,
        public readonly KernelInput $input,
        public RoutingState $routing,
        public ?array $decision,
        public ExecutionState $execution,
        public ?array $output,
        public readonly AuditState $audit,
    ) {}

    public function hasDecisionReceipt(): bool
    {
        return is_array($this->decision) && isset($this->decision['receipt_id']);
    }
}

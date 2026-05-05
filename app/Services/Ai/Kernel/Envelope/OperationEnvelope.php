<?php

namespace App\Services\Ai\Kernel\Envelope;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;

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
        public ?DecisionReceipt $decision,
        public ExecutionState $execution,
        public ?KernelOutput $output,
        public readonly AuditState $audit,
    ) {}

    public function hasDecisionReceipt(): bool
    {
        return $this->decision instanceof DecisionReceipt;
    }
}

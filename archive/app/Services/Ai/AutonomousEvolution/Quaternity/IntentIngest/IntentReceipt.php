<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/** Immutable receipt VO for one operator-intent fact decision. */
final class IntentReceipt
{
    public const SCHEMA = 'operator.intent.v1';

    public function __construct(
        public readonly string $receiptId,
        public readonly string $factId,
        public readonly string $schema,
        public readonly string $recordedAt,
        public readonly string $decision,
        public readonly string $decisionReason,
        public readonly ?string $downstreamRef,
    ) {}

    /**
     * @return array{receipt_id:string, fact_id:string, schema:string, recorded_at:string, decision:string, decision_reason:string, downstream_ref:?string}
     */
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'fact_id' => $this->factId,
            'schema' => $this->schema,
            'recorded_at' => $this->recordedAt,
            'decision' => $this->decision,
            'decision_reason' => $this->decisionReason,
            'downstream_ref' => $this->downstreamRef,
        ];
    }
}

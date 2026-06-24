<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

use JsonSerializable;

final class AutopoieticConstitutionReceipt implements JsonSerializable
{
    public function __construct(
        public readonly string $receiptId,
        public readonly string $recordedAt,
        public readonly string $actionCategory,
        public readonly string $targetScope,
        public readonly string $operatorSignature,
        public readonly string $priorFingerprint,
        public readonly string $payloadHash,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            receiptId: (string) ($payload['receipt_id'] ?? ''),
            recordedAt: (string) ($payload['recorded_at'] ?? ''),
            actionCategory: (string) ($payload['action_category'] ?? ''),
            targetScope: (string) ($payload['target_scope'] ?? ''),
            operatorSignature: (string) ($payload['operator_signature'] ?? ''),
            priorFingerprint: (string) ($payload['prior_fingerprint'] ?? ''),
            payloadHash: (string) ($payload['payload_hash'] ?? ''),
        );
    }

    /**
     * @return array{receipt_id:string,recorded_at:string,action_category:string,target_scope:string,operator_signature:string,prior_fingerprint:string,payload_hash:string}
     */
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'recorded_at' => $this->recordedAt,
            'action_category' => $this->actionCategory,
            'target_scope' => $this->targetScope,
            'operator_signature' => $this->operatorSignature,
            'prior_fingerprint' => $this->priorFingerprint,
            'payload_hash' => $this->payloadHash,
        ];
    }

    /**
     * @return array{receipt_id:string,recorded_at:string,action_category:string,target_scope:string,operator_signature:string,prior_fingerprint:string,payload_hash:string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

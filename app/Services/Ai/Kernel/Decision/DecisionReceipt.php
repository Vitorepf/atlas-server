<?php

namespace App\Services\Ai\Kernel\Decision;

use Carbon\CarbonImmutable;

final readonly class DecisionReceipt
{
    public const SCHEMA_VERSION = 'atlas.decide.v2';

    /**
     * @param  array<string,mixed>  $providerSelection
     * @param  array<string,mixed>  $budgets
     * @param  array<int,string>  $requiredGates
     * @param  array<int,string>  $requiredEvidence
     * @param  array<string,mixed>  $repairPolicy
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $receiptId,
        public string $envelopeId,
        public string $schemaVersion,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        public string $domain,
        public string $flow,
        public string $risk,
        public array $providerSelection,
        public array $budgets,
        public array $requiredGates,
        public array $requiredEvidence,
        public array $repairPolicy,
        public string $inputsHash,
        public string $receiptHash,
        public ?string $parentReceiptId,
        public string $chainHash,
        public array $metadata = [],
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThan($this->expiresAt);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'envelope_id' => $this->envelopeId,
            'schema_version' => $this->schemaVersion,
            'issued_at' => $this->issuedAt->toISOString(),
            'expires_at' => $this->expiresAt->toISOString(),
            'domain' => $this->domain,
            'flow' => $this->flow,
            'risk' => $this->risk,
            'provider_selection' => $this->providerSelection,
            'budgets' => $this->budgets,
            'required_gates' => $this->requiredGates,
            'required_evidence' => $this->requiredEvidence,
            'repair_policy' => $this->repairPolicy,
            'inputs_hash' => $this->inputsHash,
            'receipt_hash' => $this->receiptHash,
            'parent_receipt_id' => $this->parentReceiptId,
            'chain_hash' => $this->chainHash,
            'metadata' => $this->metadata,
        ];
    }
}

<?php

namespace App\Services\Ai\Kernel\Decision;

use Carbon\CarbonImmutable;

final readonly class DecisionReceipt
{
    public const SCHEMA_VERSION = 'atlas.decide.v2';

    public const SCHEMA_VERSION_V3 = 'atlas.decide.v3';

    public const RECEIPT_V2_KEY = 'receipt_v2';

    public const RECEIPT_V3_KEY = 'receipt_v3';

    /**
     * V3 reserves the complete standing-authority envelope. EXPAND only
     * parses/verifies these bytes; issuance remains the immutable V2 writer
     * until the later CANARY gate.
     */
    public const V3_AUTHORITY_FIELDS = [
        'authority_id',
        'issuer_key_id',
        'lifecycle',
        'audience',
        'scope',
        'effect',
        'budget',
        'nonce',
        'revocation_head',
        'separation_of_duties',
    ];

    /**
     * @param  array<int,string>  $requiredGates
     * @param  array<int,string>  $requiredEvidence
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $receiptId,
        public string $envelopeId,
        public string $schemaVersion,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        public bool $dryRun,
        public string $signedBy,
        public string $domain,
        public string $flow,
        public string $risk,
        public DecisionProviderSelection $providerSelection,
        public DecisionBudgets $budgets,
        public array $requiredGates,
        public array $requiredEvidence,
        public DecisionRepairPolicy $repairPolicy,
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
            'dry_run' => $this->dryRun,
            'signed_by' => $this->signedBy,
            'domain' => $this->domain,
            'flow' => $this->flow,
            'risk' => $this->risk,
            'provider_selection' => $this->providerSelection->toArray(),
            'budgets' => $this->budgets->toArray(),
            'required_gates' => $this->requiredGates,
            'required_evidence' => $this->requiredEvidence,
            'repair_policy' => $this->repairPolicy->toArray(),
            'inputs_hash' => $this->inputsHash,
            'receipt_hash' => $this->receiptHash,
            'parent_receipt_id' => $this->parentReceiptId,
            'chain_hash' => $this->chainHash,
            'metadata' => $this->metadata,
        ];
    }
}

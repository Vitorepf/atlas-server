<?php

namespace App\Services\Ai\Kernel\Decision;

final readonly class DecisionReceiptRuntimeViolation
{
    public function __construct(
        public string $errorCode,
        public string $message,
        public ?string $receiptId = null,
        public ?string $envelopeId = null,
        public ?string $expiresAt = null,
        public ?bool $dryRun = null,
        public ?string $schemaVersion = null,
        public ?string $expectedProvider = null,
        public ?string $actualProvider = null,
        public ?string $expectedModel = null,
        public ?string $actualModel = null,
    ) {}

    /**
     * @return array{error_code:string,message:string,receipt_id:?string,envelope_id:?string,expires_at:?string,dry_run:?bool,schema_version:?string,expected_provider:?string,actual_provider:?string,expected_model:?string,actual_model:?string}
     */
    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'receipt_id' => $this->receiptId,
            'envelope_id' => $this->envelopeId,
            'expires_at' => $this->expiresAt,
            'dry_run' => $this->dryRun,
            'schema_version' => $this->schemaVersion,
            'expected_provider' => $this->expectedProvider,
            'actual_provider' => $this->actualProvider,
            'expected_model' => $this->expectedModel,
            'actual_model' => $this->actualModel,
        ];
    }
}

<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;

final readonly class RepairRequest
{
    /**
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $envelopeId,
        public ?string $receiptId,
        public FailureClassification $failure,
        public RepairPolicy $policy,
        public int $currentAttempt,
        public array $evidenceRefs = [],
        public bool $dryRun = true,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $failure = RepairPayloadNormalizer::arrayPayload($payload['failure_classification'] ?? $payload['failure'] ?? []);
        $domain = FailureDomain::tryFrom((string) ($failure['failure_domain'] ?? $failure['domain'] ?? '')) ?? FailureDomain::Unknown;

        return new self(
            envelopeId: RepairPayloadNormalizer::string($payload['envelope_id'] ?? '', ''),
            receiptId: RepairPayloadNormalizer::nullableString($payload['receipt_id'] ?? null),
            failure: new FailureClassification(
                domain: $domain,
                source: RepairPayloadNormalizer::string($failure['source'] ?? null, 'repair_request'),
                signals: self::stringList($failure['signals'] ?? []),
                confidence: RepairPayloadNormalizer::boundedFloat($failure['confidence'] ?? null, 1.0, 0.0, 1.0),
                metadata: is_array($failure['metadata'] ?? null) ? $failure['metadata'] : [],
            ),
            policy: RepairPolicy::fromArray(is_array($payload['policy'] ?? null) ? $payload['policy'] : $payload),
            currentAttempt: (int) ($payload['current_attempt'] ?? 0),
            evidenceRefs: self::stringList($payload['evidence_refs'] ?? []),
            dryRun: RepairPayloadNormalizer::boolean($payload['dry_run'] ?? true),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'envelope_id' => $this->envelopeId,
            'receipt_id' => $this->receiptId,
            'failure_classification' => $this->failure->toArray(),
            'policy' => $this->policy->toArray(),
            'current_attempt' => $this->currentAttempt,
            'evidence_refs' => $this->evidenceRefs,
            'dry_run' => $this->dryRun,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $value): ?string => is_string($value) ? trim($value) : null,
                $values,
            ),
            fn (?string $value): bool => $value !== null && $value !== '',
        ));
    }
}

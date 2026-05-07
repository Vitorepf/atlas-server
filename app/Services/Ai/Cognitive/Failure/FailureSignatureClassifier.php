<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureClassifier as KernelFailureClassifier;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use Illuminate\Support\Str;

class FailureSignatureClassifier
{
    public const SCHEMA_VERSION = 'atlas.cognitive.failure_signature.v1';

    public function __construct(
        private readonly KernelFailureClassifier $classifier,
        private readonly FailureSimilarityComputer $similarity,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  AtlasLedgerEvent|array<string,mixed>|string  $failure
     * @return array<string,mixed>
     */
    public function classify(AtlasLedgerEvent|array|string $failure, ?string $message = null): array
    {
        return $this->slo->measure('cognitive.failure.classify', function () use ($failure, $message): array {
            $payload = $this->payload($failure, $message);
            $classification = $this->classifier->classify($payload);
            $category = $this->categoryFor($classification->domain);
            $subCause = $this->subCause($classification->domain);
            $features = $this->canonicalFeatures($payload, $classification);
            $signature = [
                'schema_version' => self::SCHEMA_VERSION,
                'envelope_id' => $this->string(data_get($payload, 'envelope_id', 'failure:'.Str::ulid()), 80),
                'source_ledger_event_id' => $this->nullableString(data_get($payload, 'source_ledger_event_id', data_get($payload, 'event_id')), 80),
                'domain' => $this->domain($payload),
                'category' => $category,
                'sub_cause' => $subCause,
                'context_summary' => $this->contextSummary($payload),
                'canonical_features' => $features,
                'vector_embedding' => null,
                'recorded_at' => data_get($payload, 'occurred_at', now()->toJSON()),
            ];
            $signature['signature_key'] = $this->similarity->signatureKey($signature);

            return $signature;
        }, [
            'domain' => is_array($failure) ? (string) data_get($failure, 'domain', 'unknown') : 'unknown',
        ]);
    }

    /**
     * @param  AtlasLedgerEvent|array<string,mixed>|string  $failure
     * @return array<string,mixed>
     */
    private function payload(AtlasLedgerEvent|array|string $failure, ?string $message): array
    {
        if ($failure instanceof AtlasLedgerEvent) {
            return [
                'event_id' => $failure->event_id,
                'source_ledger_event_id' => $failure->event_id,
                'event_type' => $failure->event_type,
                'envelope_id' => $failure->envelope_id,
                'tenant_id' => $failure->tenant_id,
                'operator_id' => $failure->operator_id,
                'occurred_at' => $failure->occurred_at?->toJSON(),
                ...((array) $failure->payload),
            ];
        }

        if (is_array($failure)) {
            return $failure;
        }

        return [
            'message' => trim($failure.' '.($message ?? '')),
            'domain' => 'general',
            'event_type' => 'manual_failure',
            'envelope_id' => 'failure:'.Str::ulid(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function domain(array $payload): string
    {
        $domain = data_get($payload, 'domain')
            ?? data_get($payload, 'payload.domain')
            ?? data_get($payload, 'metadata.domain')
            ?? data_get($payload, 'flow');

        $domain = strtolower(trim((string) $domain));
        if ($domain === '') {
            return 'general';
        }

        return str($domain)->before('.')->replaceMatches('/[^a-z0-9_]+/', '_')->limit(64, '')->toString();
    }

    private function categoryFor(FailureDomain $domain): string
    {
        return match ($domain) {
            FailureDomain::InputMalformed,
            FailureDomain::InputAttachmentUnavailable,
            FailureDomain::IntentAmbiguous => 'communication',
            FailureDomain::DomainUnsupported,
            FailureDomain::ProfileMissing,
            FailureDomain::PolicyDenied,
            FailureDomain::BudgetExceeded,
            FailureDomain::DecisionExpired,
            FailureDomain::DecisionInvalid => 'decision',
            FailureDomain::EvidenceMissing,
            FailureDomain::GateFailed,
            FailureDomain::RepairExhausted,
            FailureDomain::SurfaceContractViolation => 'process',
            FailureDomain::PrivacyViolation,
            FailureDomain::SecurityFinding,
            FailureDomain::ComplianceViolation => 'safety',
            FailureDomain::ContextPackFailed,
            FailureDomain::MemoryUnavailable => 'knowledge_gap',
            default => 'technical',
        };
    }

    private function subCause(FailureDomain $domain): string
    {
        return str($domain->value)->replace('.', '_')->replace('-', '_')->limit(128, '')->toString();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalFeatures(array $payload, FailureClassification $classification): array
    {
        return [
            'event_type' => (string) data_get($payload, 'event_type', 'unknown'),
            'status_code' => data_get($payload, 'status_code', data_get($payload, 'error.status_code')),
            'error_class' => data_get($payload, 'error_class', data_get($payload, 'throwable_class')),
            'message_hash' => substr(hash('sha256', $this->contextSummary($payload)), 0, 16),
            'classification' => $classification->toArray(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function contextSummary(array $payload): string
    {
        $message = data_get($payload, 'message')
            ?? data_get($payload, 'error.message')
            ?? data_get($payload, 'reason')
            ?? data_get($payload, 'status')
            ?? data_get($payload, 'event_type')
            ?? 'Failure event without message.';

        return Str::limit($this->redact((string) $message), 500, '');
    }

    private function redact(string $value): string
    {
        $value = preg_replace('/(sk|pk|rk|ghp|gho|xox[baprs])-[-_a-zA-Z0-9]{12,}/', '[REDACTED_SECRET]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $value) ?? $value;

        return trim($value);
    }

    private function string(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }
}

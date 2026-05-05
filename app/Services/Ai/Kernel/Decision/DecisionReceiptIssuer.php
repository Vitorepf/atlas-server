<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DecisionReceiptIssuer
{
    private const MODEL_SELECTION_MODES = [
        'auto_best_allowed',
        'auto_best_available',
        'manual_override',
    ];

    /**
     * @param  array<string,mixed>  $decision
     */
    public function issue(OperationEnvelope $envelope, array $decision): DecisionReceipt
    {
        $issuedAt = isset($decision['issued_at']) ? CarbonImmutable::parse($decision['issued_at']) : CarbonImmutable::now();
        $ttlSeconds = max(1, (int) ($decision['ttl_seconds'] ?? 30));
        $expiresAt = isset($decision['expires_at']) ? CarbonImmutable::parse($decision['expires_at']) : $issuedAt->addSeconds($ttlSeconds);
        $receiptId = $this->string($decision['receipt_id'] ?? (string) Str::ulid());
        $parentReceiptId = $this->optionalString($decision['parent_receipt_id'] ?? null);
        $parentChainHash = $this->optionalString($decision['parent_chain_hash'] ?? null);
        $domain = $this->string($decision['domain'] ?? $envelope->routing->domain ?? 'general') ?: 'general';
        $flow = $this->string($decision['flow'] ?? $envelope->routing->flow ?? $domain.'.default') ?: $domain.'.default';
        $risk = $this->validRisk($decision['risk'] ?? 'medium');
        $providerSelection = $this->providerSelection($decision['provider_selection'] ?? []);
        $budgets = is_array($decision['budgets'] ?? null) ? $decision['budgets'] : [];
        $requiredGates = $this->stringList($decision['required_gates'] ?? []);
        $requiredEvidence = $this->stringList($decision['required_evidence'] ?? ['summary']);
        $repairPolicy = is_array($decision['repair_policy'] ?? null) ? $decision['repair_policy'] : ['enabled' => false, 'max_attempts' => 0];
        $metadata = is_array($decision['metadata'] ?? null) ? $decision['metadata'] : [];
        $inputsHash = $this->hash([
            'envelope_input_hash' => $envelope->input->inputHash,
            'domain' => $domain,
            'flow' => $flow,
            'risk' => $risk,
            'provider_selection' => $providerSelection,
            'budgets' => $budgets,
            'required_gates' => $requiredGates,
            'required_evidence' => $requiredEvidence,
            'repair_policy' => $repairPolicy,
        ]);
        $receiptHash = $this->hash([
            'receipt_id' => $receiptId,
            'envelope_id' => $envelope->envelopeId,
            'schema_version' => DecisionReceipt::SCHEMA_VERSION,
            'issued_at' => $issuedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'inputs_hash' => $inputsHash,
            'parent_receipt_id' => $parentReceiptId,
        ]);
        $chainHash = $this->hash([
            'parent_chain_hash' => $parentChainHash,
            'receipt_hash' => $receiptHash,
        ]);

        return new DecisionReceipt(
            receiptId: $receiptId,
            envelopeId: $envelope->envelopeId,
            schemaVersion: DecisionReceipt::SCHEMA_VERSION,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            domain: $domain,
            flow: $flow,
            risk: $risk,
            providerSelection: $providerSelection,
            budgets: $budgets,
            requiredGates: $requiredGates,
            requiredEvidence: $requiredEvidence,
            repairPolicy: $repairPolicy,
            inputsHash: $inputsHash,
            receiptHash: $receiptHash,
            parentReceiptId: $parentReceiptId,
            chainHash: $chainHash,
            metadata: $metadata,
        );
    }

    /**
     * @param  mixed  $selection
     * @return array<string,mixed>
     */
    private function providerSelection(mixed $selection): array
    {
        $selection = is_array($selection) ? $selection : [];
        $mode = $this->string($selection['selection_mode'] ?? 'auto_best_allowed');
        if (! in_array($mode, self::MODEL_SELECTION_MODES, true)) {
            $mode = 'auto_best_allowed';
        }

        $manualOverride = is_array($selection['manual_override'] ?? null) ? $selection['manual_override'] : null;

        return [
            'primary' => $this->string($selection['primary'] ?? 'auto') ?: 'auto',
            'model' => $this->string($selection['model'] ?? 'selected-by-decide') ?: 'selected-by-decide',
            'fallbacks' => $this->stringList($selection['fallbacks'] ?? []),
            'selection_mode' => $mode,
            'selection_reason' => $this->string($selection['selection_reason'] ?? ''),
            'manual_override' => $manualOverride,
        ];
    }

    private function validRisk(mixed $risk): string
    {
        $risk = $this->string($risk);

        return in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function optionalString(mixed $value): ?string
    {
        $string = $this->string($value);

        return $string !== '' ? $string : null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => $this->string($value), $values),
            fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

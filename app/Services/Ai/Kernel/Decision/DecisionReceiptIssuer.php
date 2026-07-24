<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Support\AiStringListNormalizer;
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
        try {
            $issuedAt = isset($decision['issued_at']) ? CarbonImmutable::parse($decision['issued_at']) : CarbonImmutable::now();
        } catch (\Throwable $e) {
            $issuedAt = CarbonImmutable::now();
        }
        $ttlSeconds = max(1, (int) ($decision['ttl_seconds'] ?? 30));
        try {
            $expiresAt = isset($decision['expires_at']) ? CarbonImmutable::parse($decision['expires_at']) : $issuedAt->addSeconds($ttlSeconds);
        } catch (\Throwable $e) {
            $expiresAt = $issuedAt->addSeconds($ttlSeconds);
        }
        $receiptId = $this->string($decision['receipt_id'] ?? (string) Str::ulid());
        $dryRun = (bool) ($decision['dry_run'] ?? false);
        $signedBy = $this->string($decision['signed_by'] ?? 'atlas.decide.v2') ?: 'atlas.decide.v2';
        $parentReceiptId = $this->optionalString($decision['parent_receipt_id'] ?? null);
        $parentChainHash = $this->optionalString($decision['parent_chain_hash'] ?? null);
        $domain = $this->string($decision['domain'] ?? $envelope->routing->domain ?? 'general') ?: 'general';
        $flow = $this->string($decision['flow'] ?? $envelope->routing->flow ?? $domain.'.default') ?: $domain.'.default';
        $risk = $this->validRisk($decision['risk'] ?? 'medium');
        $providerSelection = $this->providerSelection($decision['provider_selection'] ?? []);
        $budgets = DecisionBudgets::fromArray(is_array($decision['budgets'] ?? null) ? $decision['budgets'] : []);
        $requiredGates = AiStringListNormalizer::uniqueTrimmedCastItemsToStrings($decision['required_gates'] ?? []);
        $requiredEvidence = AiStringListNormalizer::uniqueTrimmedCastItemsToStrings($decision['required_evidence'] ?? ['summary']);
        $repairPolicy = DecisionRepairPolicy::fromArray(is_array($decision['repair_policy'] ?? null) ? $decision['repair_policy'] : ['enabled' => false, 'max_attempts' => 0]);
        $metadata = is_array($decision['metadata'] ?? null) ? $decision['metadata'] : [];
        $metadata['envelope_input_hash'] = $metadata['envelope_input_hash'] ?? $envelope->input->inputHash;
        if ($parentChainHash !== null) {
            $metadata['parent_chain_hash'] = $metadata['parent_chain_hash'] ?? $parentChainHash;
        }
        $inputsHash = $this->hash([
            'envelope_input_hash' => $envelope->input->inputHash,
            'domain' => $domain,
            'flow' => $flow,
            'risk' => $risk,
            'provider_selection' => $providerSelection->toArray(),
            'budgets' => $budgets->toArray(),
            'required_gates' => $requiredGates,
            'required_evidence' => $requiredEvidence,
            'repair_policy' => $repairPolicy->toArray(),
        ]);
        $receiptHash = $this->hash([
            'receipt_id' => $receiptId,
            'envelope_id' => $envelope->envelopeId,
            'schema_version' => DecisionReceipt::SCHEMA_VERSION,
            'issued_at' => $issuedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'dry_run' => $dryRun,
            'signed_by' => $signedBy,
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
            dryRun: $dryRun,
            signedBy: $signedBy,
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
     * G8 — per-step INTERMEDIATE receipt inside a mission/loop.
     *
     * Forces the parent linkage (parent_receipt_id + parent_chain_hash come from the
     * PARENT receipt, never from the caller's $decision) and stamps the step metadata,
     * then delegates to issue() verbatim — so the verifiable chain link
     * child.chainHash = hash(parent.chainHash + child.receiptHash) is the one issue()
     * already computes, never reimplemented here. The envelope is the parent's
     * operation envelope (a receipt does not carry it, so the caller passes it back).
     *
     * @param  array<string,mixed>  $decision
     */
    public function issueIntermediate(OperationEnvelope $envelope, DecisionReceipt $parent, int $stepIndex, array $decision): DecisionReceipt
    {
        $decision['parent_receipt_id'] = $parent->receiptId;
        $decision['parent_chain_hash'] = $parent->chainHash;

        $metadata = is_array($decision['metadata'] ?? null) ? $decision['metadata'] : [];
        $metadata['mission_step_index'] = $stepIndex;
        $metadata['intermediate'] = true;
        // issue() only fills parent_chain_hash into metadata when absent; force the
        // parent's real value so a caller-supplied stale hash can never win.
        $metadata['parent_chain_hash'] = $parent->chainHash;
        $decision['metadata'] = $metadata;

        return $this->issue($envelope, $decision);
    }

    /**
     * CANARY: explicit selector for NEW issuances only. Never rewrites historical
     * V2 bytes — only decides whether a companion receipt_v3 envelope is emitted
     * alongside a freshly issued V2 receipt.
     */
    public function isV3CanarySelected(string $receiptId): bool
    {
        $percent = max(0, min(100, (int) config('atlas.ai.decision_receipt_v3_canary_percent', 0)));
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        $bucket = hexdec(substr(hash('sha256', 'decision_receipt_v3_canary:'.$receiptId), 0, 8)) % 100;

        return $bucket < $percent;
    }

    /**
     * Build a canary V3 companion envelope from an already-issued V2 receipt.
     * Historical signed V2 fields are copied, never mutated in place.
     *
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>|null
     */
    public function issueV3CanaryCompanion(DecisionReceipt $receipt, OperationEnvelope $envelope, array $decision = []): ?array
    {
        if (! $this->isV3CanarySelected($receipt->receiptId)) {
            return null;
        }

        $v2 = $receipt->toArray();
        $authority = $this->canaryAuthorityEnvelope($receipt, $envelope, $decision);
        $v3 = [
            'receipt_id' => $v2['receipt_id'],
            'envelope_id' => $v2['envelope_id'],
            'schema_version' => DecisionReceipt::SCHEMA_VERSION_V3,
            'issued_at' => $v2['issued_at'],
            'expires_at' => $v2['expires_at'],
            'dry_run' => $v2['dry_run'],
            'signed_by' => 'atlas.decide.v3-canary',
            'domain' => $v2['domain'],
            'flow' => $v2['flow'],
            'risk' => $v2['risk'],
            'provider_selection' => $v2['provider_selection'],
            'budgets' => $v2['budgets'],
            'required_gates' => $v2['required_gates'],
            'required_evidence' => $v2['required_evidence'],
            'repair_policy' => $v2['repair_policy'],
            'inputs_hash' => $v2['inputs_hash'],
            'parent_receipt_id' => $v2['parent_receipt_id'],
            'chain_hash' => $v2['chain_hash'],
            'authority' => $authority,
        ];
        $v3['receipt_hash'] = DecisionReceiptHash::v3FullEnvelopeHash($v3);

        return $v3;
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function canaryAuthorityEnvelope(DecisionReceipt $receipt, OperationEnvelope $envelope, array $decision): array
    {
        $tenantId = $this->string($envelope->operator->tenantId ?? 'tenant-canary') ?: 'tenant-canary';
        $principalId = $this->string($envelope->operator->operatorId ?? 'principal-canary') ?: 'principal-canary';
        if (in_array($tenantId, ['default', 'system', 'unknown'], true)) {
            $tenantId = 'tenant-canary-'.$receipt->receiptId;
        }
        if (in_array($principalId, ['default', 'system', 'unknown'], true)) {
            $principalId = 'principal-canary-'.$receipt->receiptId;
        }
        $workspace = $this->string($envelope->operator->workspace ?? base_path()) ?: base_path();
        $mode = $this->string(data_get($decision, 'metadata.mode')
            ?? data_get($decision, 'mode')
            ?? 'dev') ?: 'dev';
        if (! in_array($mode, ['dev', 'forge', 'autonomos'], true)) {
            $mode = 'dev';
        }

        return [
            'authority_id' => 'canary-'.$receipt->receiptId,
            'issuer_key_id' => 'atlas.decide.v3-canary',
            'lifecycle' => ['status' => 'active', 'revision' => 1],
            'audience' => [
                'tenant_id' => $tenantId,
                'principal_id' => $principalId,
            ],
            'scope' => [
                'workspace_id' => hash('sha256', $workspace),
                'modes' => [$mode],
                'capability' => $receipt->flow,
            ],
            'effect' => [
                'class' => 'provider_tool_sandbox_mutation',
                'allowed' => ! $receipt->dryRun,
            ],
            'budget' => [
                'budget_id' => 'budget-canary-'.$receipt->receiptId,
                'max_effects' => 1,
            ],
            'nonce' => hash('sha256', 'nonce:'.$receipt->receiptId.':'.$receipt->receiptHash),
            'revocation_head' => hash('sha256', 'revocation:'.$receipt->chainHash),
            'separation_of_duties' => [
                'issuer_principal_id' => 'issuer-'.$principalId,
                'executor_principal_id' => 'executor-'.$principalId,
            ],
        ];
    }

    private function providerSelection(mixed $selection): DecisionProviderSelection
    {
        $selection = is_array($selection) ? $selection : [];
        $selection = [
            'primary' => $this->string($selection['primary'] ?? 'auto') ?: 'auto',
            'model' => $this->string($selection['model'] ?? 'selected-by-decide') ?: 'selected-by-decide',
            'fallbacks' => AiStringListNormalizer::uniqueTrimmedCastItemsToStrings($selection['fallbacks'] ?? []),
            'selection_mode' => $this->validModelSelectionMode($selection['selection_mode'] ?? 'auto_best_allowed'),
            'selection_reason' => $this->string($selection['selection_reason'] ?? ''),
            'selection_explanation' => is_array($selection['selection_explanation'] ?? null) ? $selection['selection_explanation'] : null,
            'confidence_score' => is_numeric($selection['confidence_score'] ?? null) ? (int) $selection['confidence_score'] : null,
            'confidence_band' => $this->optionalString($selection['confidence_band'] ?? null),
            'manual_override' => is_array($selection['manual_override'] ?? null) ? $selection['manual_override'] : null,
        ];

        return DecisionProviderSelection::fromArray($selection);
    }

    private function validModelSelectionMode(mixed $mode): string
    {
        $mode = $this->string($mode);

        return in_array($mode, self::MODEL_SELECTION_MODES, true) ? $mode : 'auto_best_allowed';
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
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return DecisionReceiptHash::hash($payload);
    }
}

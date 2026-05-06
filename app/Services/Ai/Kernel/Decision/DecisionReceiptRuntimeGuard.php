<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Models\AiJob;
use Carbon\CarbonImmutable;

class DecisionReceiptRuntimeGuard
{
    /**
     * @return array<string,mixed>
     */
    public function receiptForJob(AiJob $job): array
    {
        $metadataReceipt = data_get($job->metadata, 'decision_receipt');
        if (is_array($metadataReceipt)) {
            return $metadataReceipt;
        }

        $payloadReceipt = data_get($job->payload, 'decision_receipt');

        return is_array($payloadReceipt) ? $payloadReceipt : [];
    }

    public function violationForJob(AiJob $job, ?string $runtimeProvider = null, ?string $runtimeModel = null): ?DecisionReceiptRuntimeViolation
    {
        return $this->violationForReceipt(
            $this->receiptForJob($job),
            runtimeProvider: $runtimeProvider ?? $this->stringOrNull($job->provider),
            runtimeModel: $runtimeModel ?? $this->stringOrNull($job->model),
            runtimeStage: $this->stringOrNull(data_get($job->metadata, 'atlas_decide_stage'))
                ?? $this->stringOrNull(data_get($job->payload, 'atlas_decide_stage')),
        );
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    public function violationForReceipt(
        array $receipt,
        ?string $runtimeProvider = null,
        ?string $runtimeModel = null,
        ?string $runtimeStage = null,
    ): ?DecisionReceiptRuntimeViolation {
        $receiptV2 = data_get($receipt, 'receipt_v2');
        if (! is_array($receiptV2)) {
            return null;
        }

        $receiptId = data_get($receiptV2, 'receipt_id');
        $envelopeId = data_get($receiptV2, 'envelope_id');
        $schemaVersion = data_get($receiptV2, 'schema_version');
        $expiresAt = data_get($receiptV2, 'expires_at');
        $dryRun = data_get($receiptV2, 'dry_run');

        $base = [
            'receiptId' => is_scalar($receiptId) ? (string) $receiptId : null,
            'envelopeId' => is_scalar($envelopeId) ? (string) $envelopeId : null,
            'expiresAt' => is_scalar($expiresAt) ? (string) $expiresAt : null,
            'dryRun' => is_bool($dryRun) ? $dryRun : null,
            'schemaVersion' => is_scalar($schemaVersion) ? (string) $schemaVersion : null,
        ];

        if (($base['schemaVersion'] ?? null) !== DecisionReceipt::SCHEMA_VERSION) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_invalid',
                message: 'DecisionReceipt invalido: schema_version ausente ou diferente de atlas.decide.v2.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
            );
        }

        if ($base['dryRun'] === true) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_dry_run',
                message: 'DecisionReceipt de preview/dry-run nao pode ser consumido pelo Data Plane.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
            );
        }

        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_invalid',
                message: 'DecisionReceipt invalido: expires_at ausente.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
            );
        }

        try {
            $expires = CarbonImmutable::parse($expiresAt);
        } catch (\Throwable) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_invalid',
                message: 'DecisionReceipt invalido: expires_at nao e uma data valida.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
            );
        }

        if (CarbonImmutable::now()->greaterThan($expires)) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_expired',
                message: 'DecisionReceipt expirado antes da execucao do provider.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
            );
        }

        $hashViolation = $this->hashIntegrityViolation($receiptV2, $base);
        if ($hashViolation instanceof DecisionReceiptRuntimeViolation) {
            return $hashViolation;
        }

        $providerViolation = $this->providerSelectionViolation($receiptV2, $runtimeProvider, $runtimeModel, $runtimeStage, $base);
        if ($providerViolation instanceof DecisionReceiptRuntimeViolation) {
            return $providerViolation;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receiptV2
     * @param  array{receiptId:?string,envelopeId:?string,expiresAt:?string,dryRun:?bool,schemaVersion:?string}  $base
     */
    private function hashIntegrityViolation(array $receiptV2, array $base): ?DecisionReceiptRuntimeViolation
    {
        $receiptHash = $this->stringOrNull(data_get($receiptV2, 'receipt_hash'));
        if ($receiptHash === null) {
            return null;
        }

        $inputsHash = $this->stringOrNull(data_get($receiptV2, 'inputs_hash'));
        $envelopeInputHash = $this->stringOrNull(data_get($receiptV2, 'metadata.envelope_input_hash'));
        if ($inputsHash !== null && $envelopeInputHash !== null) {
            $expectedInputsHash = DecisionReceiptHash::hash([
                'envelope_input_hash' => $envelopeInputHash,
                'domain' => $this->stringOrDefault(data_get($receiptV2, 'domain'), 'general'),
                'flow' => $this->stringOrDefault(data_get($receiptV2, 'flow'), $this->stringOrDefault(data_get($receiptV2, 'domain'), 'general').'.default'),
                'risk' => $this->stringOrDefault(data_get($receiptV2, 'risk'), 'medium'),
                'provider_selection' => $this->arrayOrEmpty(data_get($receiptV2, 'provider_selection')),
                'budgets' => $this->arrayOrEmpty(data_get($receiptV2, 'budgets')),
                'required_gates' => $this->arrayOrEmpty(data_get($receiptV2, 'required_gates')),
                'required_evidence' => $this->arrayOrEmpty(data_get($receiptV2, 'required_evidence')),
                'repair_policy' => $this->arrayOrEmpty(data_get($receiptV2, 'repair_policy')),
            ]);

            if (! hash_equals($expectedInputsHash, $inputsHash)) {
                return $this->hashMismatchViolation($base, 'DecisionReceipt invalido: inputs_hash nao corresponde ao payload assinado.');
            }
        }

        $expectedReceiptHash = DecisionReceiptHash::hash([
            'receipt_id' => $this->stringOrDefault(data_get($receiptV2, 'receipt_id'), ''),
            'envelope_id' => $this->stringOrDefault(data_get($receiptV2, 'envelope_id'), ''),
            'schema_version' => $this->stringOrDefault(data_get($receiptV2, 'schema_version'), ''),
            'issued_at' => $this->stringOrDefault(data_get($receiptV2, 'issued_at'), ''),
            'expires_at' => $this->stringOrDefault(data_get($receiptV2, 'expires_at'), ''),
            'dry_run' => (bool) data_get($receiptV2, 'dry_run', false),
            'signed_by' => $this->stringOrDefault(data_get($receiptV2, 'signed_by'), ''),
            'inputs_hash' => $this->stringOrDefault(data_get($receiptV2, 'inputs_hash'), ''),
            'parent_receipt_id' => $this->stringOrNull(data_get($receiptV2, 'parent_receipt_id')),
        ]);

        if (! hash_equals($expectedReceiptHash, $receiptHash)) {
            return $this->hashMismatchViolation($base, 'DecisionReceipt invalido: receipt_hash nao corresponde ao payload assinado.');
        }

        $chainHash = $this->stringOrNull(data_get($receiptV2, 'chain_hash'));
        if ($chainHash !== null) {
            $expectedChainHash = DecisionReceiptHash::hash([
                'parent_chain_hash' => $this->stringOrNull(data_get($receiptV2, 'parent_chain_hash'))
                    ?? $this->stringOrNull(data_get($receiptV2, 'metadata.parent_chain_hash')),
                'receipt_hash' => $receiptHash,
            ]);

            if (! hash_equals($expectedChainHash, $chainHash)) {
                return $this->hashMismatchViolation($base, 'DecisionReceipt invalido: chain_hash nao corresponde ao receipt_hash.');
            }
        }

        return null;
    }

    /**
     * @param  array{receiptId:?string,envelopeId:?string,expiresAt:?string,dryRun:?bool,schemaVersion:?string}  $base
     */
    private function hashMismatchViolation(array $base, string $message): DecisionReceiptRuntimeViolation
    {
        return new DecisionReceiptRuntimeViolation(
            errorCode: 'decision_receipt_hash_mismatch',
            message: $message,
            receiptId: $base['receiptId'],
            envelopeId: $base['envelopeId'],
            expiresAt: $base['expiresAt'],
            dryRun: $base['dryRun'],
            schemaVersion: $base['schemaVersion'],
        );
    }

    /**
     * @param  array<string,mixed>  $receiptV2
     * @param  array{receiptId:?string,envelopeId:?string,expiresAt:?string,dryRun:?bool,schemaVersion:?string}  $base
     */
    private function providerSelectionViolation(
        array $receiptV2,
        ?string $runtimeProvider,
        ?string $runtimeModel,
        ?string $runtimeStage,
        array $base,
    ): ?DecisionReceiptRuntimeViolation {
        $selection = data_get($receiptV2, 'provider_selection');
        if (! is_array($selection)) {
            return null;
        }

        $expectedProvider = $this->stringOrNull(data_get($selection, 'primary'));
        $expectedModel = $this->stringOrNull(data_get($selection, 'model'));
        $runtimeProvider = $this->stringOrNull($runtimeProvider);
        $runtimeModel = $this->stringOrNull($runtimeModel);

        if ($expectedProvider !== null && $runtimeProvider !== null && ! $this->providerAllowed($expectedProvider, $runtimeProvider, $runtimeStage, $selection)) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_provider_mismatch',
                message: 'DecisionReceipt invalido: provider em execucao nao corresponde ao provider autorizado pelo Decide.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
                expectedProvider: $expectedProvider,
                actualProvider: $runtimeProvider,
                expectedModel: $expectedModel,
                actualModel: $runtimeModel,
            );
        }

        if ($this->modelShouldMatch($expectedModel, $runtimeModel, $runtimeStage) && $expectedModel !== $runtimeModel) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_model_mismatch',
                message: 'DecisionReceipt invalido: modelo em execucao nao corresponde ao modelo autorizado pelo Decide.',
                receiptId: $base['receiptId'],
                envelopeId: $base['envelopeId'],
                expiresAt: $base['expiresAt'],
                dryRun: $base['dryRun'],
                schemaVersion: $base['schemaVersion'],
                expectedProvider: $expectedProvider,
                actualProvider: $runtimeProvider,
                expectedModel: $expectedModel,
                actualModel: $runtimeModel,
            );
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $selection
     */
    private function providerAllowed(string $expectedProvider, string $runtimeProvider, ?string $runtimeStage, array $selection): bool
    {
        if ($expectedProvider === $runtimeProvider) {
            return true;
        }

        if ($expectedProvider === 'auto' || $expectedProvider === 'selected-by-decide') {
            return true;
        }

        if ($expectedProvider === 'claude_codex' && in_array($runtimeProvider, ['claude_codex', 'claude_cli', 'codex_cli'], true)) {
            return true;
        }

        if ($runtimeStage === 'context_scout' && $runtimeProvider === 'gemini_cli') {
            return true;
        }

        $fallbacks = array_values(array_filter(
            array_map(fn (mixed $value): string => trim((string) $value), (array) data_get($selection, 'fallbacks', [])),
            fn (string $value): bool => $value !== '',
        ));

        return in_array($runtimeProvider, $fallbacks, true);
    }

    private function modelShouldMatch(?string $expectedModel, ?string $runtimeModel, ?string $runtimeStage): bool
    {
        if ($expectedModel === null || $runtimeModel === null) {
            return false;
        }

        if (in_array($expectedModel, ['auto', 'selected-by-decide'], true)) {
            return false;
        }

        return $runtimeStage !== 'context_scout';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return $this->stringOrNull($value) ?? $default;
    }

    /**
     * @return array<mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

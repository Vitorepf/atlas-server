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
        // CANARY/mutative consumers: receipt_v3 present but non-array must never
        // fall through as "no receipt" and reach the provider.
        if (array_key_exists(DecisionReceipt::RECEIPT_V3_KEY, $receipt)
            && ! is_array($receipt[DecisionReceipt::RECEIPT_V3_KEY])) {
            return new DecisionReceiptRuntimeViolation(
                errorCode: 'decision_receipt_v3_invalid',
                message: 'DecisionReceipt v3 invalido: receipt_v3 presente mas nao e um envelope array.',
                receiptId: null,
                envelopeId: null,
                expiresAt: null,
                dryRun: null,
                schemaVersion: DecisionReceipt::SCHEMA_VERSION_V3,
            );
        }

        $receiptV2 = data_get($receipt, DecisionReceipt::RECEIPT_V2_KEY);
        if (! is_array($receiptV2)) {
            $receiptV3 = data_get($receipt, DecisionReceipt::RECEIPT_V3_KEY);

            return is_array($receiptV3)
                ? $this->v3ExpandViolation($receiptV3)
                : null;
        }

        // Dual-read: V2 remains the governing runtime receipt. Writers stay on
        // V2 until CANARY. From SHADOW onward, a co-present V3 must not contradict V2.

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

        $receiptV3 = data_get($receipt, DecisionReceipt::RECEIPT_V3_KEY);
        if (is_array($receiptV3)) {
            $shadowViolation = $this->v2V3ShadowContradiction($receiptV2, $receiptV3, $base);
            if ($shadowViolation instanceof DecisionReceiptRuntimeViolation) {
                return $shadowViolation;
            }
        }

        return null;
    }

    /**
     * SHADOW: when both transports are present, V3 must be a coherent shadow of V2.
     * Any contradiction or invalid V3 envelope vetoes before provider/effect.
     *
     * @param  array<string,mixed>  $receiptV2
     * @param  array<string,mixed>  $receiptV3
     * @param  array{receiptId:?string,envelopeId:?string,expiresAt:?string,dryRun:?bool,schemaVersion:?string}  $base
     */
    private function v2V3ShadowContradiction(array $receiptV2, array $receiptV3, array $base): ?DecisionReceiptRuntimeViolation
    {
        $v3Base = $this->baseForReceipt($receiptV3);
        if (($v3Base['schemaVersion'] ?? null) !== DecisionReceipt::SCHEMA_VERSION_V3
            || ! $this->v3EnvelopeIsParseable($receiptV3)) {
            return $this->v3Violation(
                'decision_receipt_v2_v3_shadow_contradiction',
                'DecisionReceipt shadow veto: receipt_v3 is present but not a parseable atlas.decide.v3 authority envelope.',
                $base,
            );
        }

        try {
            $hashMatches = DecisionReceiptHash::v3FullEnvelopeHashMatches($receiptV3);
        } catch (\Throwable) {
            $hashMatches = false;
        }
        if (! $hashMatches) {
            return $this->v3Violation(
                'decision_receipt_v2_v3_shadow_contradiction',
                'DecisionReceipt shadow veto: receipt_v3 integrity does not match the full authority envelope.',
                $base,
            );
        }

        $sharedKeys = [
            'receipt_id' => $this->stringOrNull(data_get($receiptV2, 'receipt_id')),
            'envelope_id' => $this->stringOrNull(data_get($receiptV2, 'envelope_id')),
            'dry_run' => data_get($receiptV2, 'dry_run'),
            'domain' => $this->stringOrNull(data_get($receiptV2, 'domain')),
            'flow' => $this->stringOrNull(data_get($receiptV2, 'flow')),
        ];
        foreach ($sharedKeys as $key => $v2Value) {
            if ($v2Value === null && $key !== 'dry_run') {
                continue;
            }
            $v3Value = $key === 'dry_run'
                ? data_get($receiptV3, 'dry_run')
                : $this->stringOrNull(data_get($receiptV3, $key));
            if ($v2Value !== $v3Value) {
                return $this->v3Violation(
                    'decision_receipt_v2_v3_shadow_contradiction',
                    'DecisionReceipt shadow veto: receipt_v2 and receipt_v3 disagree on '.$key.'.',
                    $base,
                );
            }
        }

        $v2Primary = $this->stringOrNull(data_get($receiptV2, 'provider_selection.primary'));
        $v3Primary = $this->stringOrNull(data_get($receiptV3, 'provider_selection.primary'));
        $v2Model = $this->stringOrNull(data_get($receiptV2, 'provider_selection.model'));
        $v3Model = $this->stringOrNull(data_get($receiptV3, 'provider_selection.model'));
        if (($v2Primary !== null && $v3Primary !== null && $v2Primary !== $v3Primary)
            || ($v2Model !== null && $v3Model !== null && $v2Model !== $v3Model)) {
            return $this->v3Violation(
                'decision_receipt_v2_v3_shadow_contradiction',
                'DecisionReceipt shadow veto: provider selection diverges between receipt_v2 and receipt_v3.',
                $base,
            );
        }

        return null;
    }

    /**
     * V3 bytes can be parsed and integrity-checked in EXPAND, but no V3-only
     * envelope can authorize runtime work before the CANARY/CUTOVER path.
     *
     * @param  array<string,mixed>  $receiptV3
     */
    private function v3ExpandViolation(array $receiptV3): DecisionReceiptRuntimeViolation
    {
        $base = $this->baseForReceipt($receiptV3);
        if (($base['schemaVersion'] ?? null) !== DecisionReceipt::SCHEMA_VERSION_V3
            || ! $this->v3EnvelopeIsParseable($receiptV3)) {
            return $this->v3Violation(
                'decision_receipt_v3_invalid',
                'DecisionReceipt v3 invalido: envelope de autoridade incompleto ou malformado.',
                $base,
            );
        }

        try {
            $hashMatches = DecisionReceiptHash::v3FullEnvelopeHashMatches($receiptV3);
        } catch (\Throwable) {
            $hashMatches = false;
        }
        if (! $hashMatches) {
            return $this->v3Violation(
                'decision_receipt_v3_hash_mismatch',
                'DecisionReceipt v3 invalido: receipt_hash nao cobre o envelope completo de autoridade.',
                $base,
            );
        }

        return $this->v3Violation(
            'decision_receipt_v3_non_authoritative',
            'DecisionReceipt v3 foi verificado em EXPAND, mas ainda nao e autoridade de runtime antes de CANARY/CUTOVER.',
            $base,
        );
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{receiptId:?string,envelopeId:?string,expiresAt:?string,dryRun:?bool,schemaVersion:?string}
     */
    private function baseForReceipt(array $receipt): array
    {
        $receiptId = data_get($receipt, 'receipt_id');
        $envelopeId = data_get($receipt, 'envelope_id');
        $schemaVersion = data_get($receipt, 'schema_version');
        $expiresAt = data_get($receipt, 'expires_at');
        $dryRun = data_get($receipt, 'dry_run');

        return [
            'receiptId' => is_scalar($receiptId) ? (string) $receiptId : null,
            'envelopeId' => is_scalar($envelopeId) ? (string) $envelopeId : null,
            'expiresAt' => is_scalar($expiresAt) ? (string) $expiresAt : null,
            'dryRun' => is_bool($dryRun) ? $dryRun : null,
            'schemaVersion' => is_scalar($schemaVersion) ? (string) $schemaVersion : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $receiptV3
     */
    private function v3EnvelopeIsParseable(array $receiptV3): bool
    {
        foreach ([
            'receipt_id', 'envelope_id', 'issued_at', 'expires_at', 'signed_by',
            'domain', 'flow', 'risk', 'inputs_hash', 'chain_hash',
        ] as $field) {
            if ($this->stringOrNull(data_get($receiptV3, $field)) === null) {
                return false;
            }
        }
        if (! is_bool(data_get($receiptV3, 'dry_run'))
            || preg_match('/^[a-f0-9]{64}$/', (string) data_get($receiptV3, 'inputs_hash')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) data_get($receiptV3, 'chain_hash')) !== 1
            || ! is_array(data_get($receiptV3, 'provider_selection'))
            || ! is_array(data_get($receiptV3, 'budgets'))
            || ! is_array(data_get($receiptV3, 'required_gates'))
            || ! is_array(data_get($receiptV3, 'required_evidence'))
            || ! is_array(data_get($receiptV3, 'repair_policy'))) {
            return false;
        }

        try {
            CarbonImmutable::parse((string) data_get($receiptV3, 'issued_at'));
            CarbonImmutable::parse((string) data_get($receiptV3, 'expires_at'));
        } catch (\Throwable) {
            return false;
        }

        return $this->v3AuthorityIsParseable(data_get($receiptV3, 'authority'));
    }

    private function v3AuthorityIsParseable(mixed $authority): bool
    {
        if (! is_array($authority)) {
            return false;
        }
        foreach (DecisionReceipt::V3_AUTHORITY_FIELDS as $field) {
            if (! array_key_exists($field, $authority)) {
                return false;
            }
        }
        if ($this->stringOrNull($authority['authority_id'] ?? null) === null
            || $this->stringOrNull($authority['issuer_key_id'] ?? null) === null
            || $this->stringOrNull($authority['nonce'] ?? null) === null
            || preg_match('/^[a-f0-9]{64}$/', (string) ($authority['revocation_head'] ?? '')) !== 1) {
            return false;
        }

        $lifecycle = $authority['lifecycle'];
        $audience = $authority['audience'];
        $scope = $authority['scope'];
        $effect = $authority['effect'];
        $budget = $authority['budget'];
        $sod = $authority['separation_of_duties'];
        if (! is_array($lifecycle) || ! is_array($audience) || ! is_array($scope)
            || ! is_array($effect) || ! is_array($budget) || ! is_array($sod)
            || $this->stringOrNull($lifecycle['status'] ?? null) !== 'active'
            || ! is_int($lifecycle['revision'] ?? null) || $lifecycle['revision'] < 1
            || $this->authorityIdentity($audience, 'tenant_id') === null
            || $this->authorityIdentity($audience, 'principal_id') === null
            || $this->stringOrNull($scope['workspace_id'] ?? null) === null
            || $this->stringOrNull($scope['capability'] ?? null) === null
            || ! $this->stringListIsNonEmpty($scope['modes'] ?? null)
            || $this->stringOrNull($effect['class'] ?? null) === null
            || ! is_bool($effect['allowed'] ?? null)
            || $this->stringOrNull($budget['budget_id'] ?? null) === null
            || ! is_int($budget['max_effects'] ?? null) || $budget['max_effects'] < 0) {
            return false;
        }

        $issuer = $this->authorityIdentity($sod, 'issuer_principal_id');
        $executor = $this->authorityIdentity($sod, 'executor_principal_id');

        return $issuer !== null && $executor !== null && $issuer !== $executor;
    }

    /** @param array<string,mixed> $values */
    private function authorityIdentity(array $values, string $key): ?string
    {
        $value = $this->stringOrNull($values[$key] ?? null);

        return in_array($value, ['default', 'system', 'unknown'], true) ? null : $value;
    }

    private function stringListIsNonEmpty(mixed $values): bool
    {
        if (! is_array($values) || $values === []) {
            return false;
        }

        foreach ($values as $value) {
            if ($this->stringOrNull($value) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{receiptId:?string,envelopeId:?string,expiresAt:?string,dryRun:?bool,schemaVersion:?string}  $base
     */
    private function v3Violation(string $errorCode, string $message, array $base): DecisionReceiptRuntimeViolation
    {
        return new DecisionReceiptRuntimeViolation(
            errorCode: $errorCode,
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

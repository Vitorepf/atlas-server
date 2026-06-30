<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionEvidenceHashComposerService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.completion_evidence_hash_composer.v1';

    public const MODE = 'read_only_completion_evidence_hash_composer';

    /** @return array<string, mixed> */
    public function compose(array $options = []): array
    {
        $runtimeReceipt = (array) ($options['runtime_promotion_receipt'] ?? []);
        $humanReceipt = (array) ($options['completion_receipt'] ?? []);
        $realProviderSmoke = (array) ($options['real_provider_smoke'] ?? []);

        $runtimePromotionReceipt = $this->composeReceipt(
            kind: 'runtime_promotion_receipt',
            payload: $runtimeReceipt,
            hashField: 'receipt_hash',
            hash: $runtimeReceipt === [] ? '' : $this->hashes()->runtimePromotionReceiptHash($runtimeReceipt),
        );
        $humanCompletionReceipt = $this->composeReceipt(
            kind: 'human_completion_receipt',
            payload: $humanReceipt,
            hashField: 'receipt_hash',
            hash: $humanReceipt === [] ? '' : $this->hashes()->humanCompletionReceiptHash($humanReceipt),
        );
        $realProviderSmokeComposed = $this->composeReceipt(
            kind: 'real_provider_smoke',
            payload: $realProviderSmoke,
            hashField: 'smoke_hash',
            hash: $realProviderSmoke === [] ? '' : $this->hashes()->realProviderSmokeHash($realProviderSmoke),
        );

        $runtimePromotionReceipt['persist_readiness'] = $this->persistReadiness($runtimePromotionReceipt);
        $humanCompletionReceipt['persist_readiness'] = $this->persistReadiness($humanCompletionReceipt);
        $realProviderSmokeComposed['persist_readiness'] = $this->persistReadiness($realProviderSmokeComposed);

        $providedArtifacts = array_filter(
            [$runtimePromotionReceipt, $humanCompletionReceipt, $realProviderSmokeComposed],
            static fn (array $artifact): bool => (bool) $artifact['input_present'],
        );
        $aggregateReady = $providedArtifacts !== [] && ! in_array(
            false,
            array_map(static fn (array $artifact): bool => $artifact['persist_readiness']['ready'], $providedArtifacts),
            true,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => ($runtimeReceipt !== [] || $humanReceipt !== [] || $realProviderSmoke !== []) ? 'available' : 'no_input',
            'composed_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_promotion_receipt' => $runtimePromotionReceipt,
            'human_completion_receipt' => $humanCompletionReceipt,
            'real_provider_smoke' => $realProviderSmokeComposed,
            'aggregate_all_evidence_ready' => $aggregateReady,
            'composer_policy' => [
                'read_only' => true,
                'computes_hashes_only' => true,
                'does_not_verify_operator_authority' => true,
                'does_not_persist_evidence' => true,
                'does_not_sign_for_operator' => true,
                'does_not_turn_templates_into_receipts' => true,
                'verifiers_still_required_before_persistence' => true,
            ],
            'commands_after_composition' => [
                'verify_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
                'verify_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
                'verify_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            ],
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'completion_evidence_hash_composer_does_not_persist_receipts',
                'completion_evidence_hash_composer_does_not_sign_for_operator',
                'completion_evidence_hash_composer_does_not_call_provider',
                'completion_evidence_hash_composer_does_not_spend_tokens',
                'completion_evidence_hash_composer_does_not_dispatch_work',
                'completion_evidence_hash_composer_does_not_enable_runtime',
                'completion_evidence_hash_composer_does_not_promote_completion',
            ],
        ];
        $payload['composer_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function composeReceipt(string $kind, array $payload, string $hashField, string $hash): array
    {
        if ($payload === []) {
            return [
                'kind' => $kind,
                'status' => 'no_input',
                'input_present' => false,
                'computed_hash' => '',
                'input_hash' => '',
                'input_hash_matches_computed_hash' => false,
                'payload_with_computed_hash' => [],
                'placeholder_fields' => [],
                'runtime_enabling_flags_true' => [],
            ];
        }

        $inputHash = (string) ($payload[$hashField] ?? '');
        $payloadWithHash = $payload;
        $payloadWithHash[$hashField] = $hash;

        return [
            'kind' => $kind,
            'status' => 'hash_composed',
            'input_present' => true,
            'hash_field' => $hashField,
            'computed_hash' => $hash,
            'input_hash' => $inputHash,
            'input_hash_matches_computed_hash' => $inputHash !== '' && $inputHash === $hash,
            'payload_with_computed_hash' => $payloadWithHash,
            'placeholder_fields' => $this->placeholderFields($payload, $hashField),
            'runtime_enabling_flags_true' => $this->runtimeEnablingFlagsTrue($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function persistReadiness(array $artifact): array
    {
        $blockers = [];
        if (! (bool) ($artifact['input_present'] ?? false)) {
            $blockers[] = 'missing_input';
        } else {
            $inputHash = (string) ($artifact['input_hash'] ?? '');
            if ($inputHash !== '' && ! (bool) ($artifact['input_hash_matches_computed_hash'] ?? false)) {
                $blockers[] = 'hash_mismatch';
            }
            if ((array) ($artifact['placeholder_fields'] ?? []) !== []) {
                $blockers[] = 'placeholder_fields_present';
            }
            if ((array) ($artifact['runtime_enabling_flags_true'] ?? []) !== []) {
                $blockers[] = 'runtime_enabling_flags_true';
            }
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function placeholderFields(array $payload, string $hashField): array
    {
        $fields = [];
        foreach ($payload as $field => $value) {
            if ((string) $field === $hashField) {
                continue;
            }
            if (is_string($value) && $this->isPlaceholderValue($value)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    private function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || str_starts_with($normalized, '<') || str_starts_with($normalized, '__')) {
            return true;
        }

        foreach ([
            'seu_nome',
            'seu nome',
            'operador',
            'motivo real',
            'pelo menos 32 caracteres',
            'substitua',
            'placeholder',
            'todo',
            'synthetic',
            'fixture-only',
            'fixture_only',
            'test_only',
            'test-only',
            'fake',
            'simulated',
            'mock-',
            'dummy',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function runtimeEnablingFlagsTrue(array $payload): array
    {
        $flags = [];
        foreach ([
            'execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ] as $flag) {
            if (($payload[$flag] ?? false) === true) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    private function hashes(): AtlasSelfConstructionCompletionEvidenceHashService
    {
        return new AtlasSelfConstructionCompletionEvidenceHashService;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['composed_at'], $payload['composer_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}

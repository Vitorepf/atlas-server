<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeEvidenceDossierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_evidence_dossier.v1';

    public const MODE = 'read_only_real_provider_smoke_evidence_dossier';

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $smoke = (array) ($options['real_provider_smoke'] ?? []);
        $certification = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($smoke);
        $requiredFields = [
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
            'smoke_hash',
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ];
        $missingFields = array_values(array_filter($requiredFields, static fn (string $field): bool => trim((string) ($smoke[$field] ?? '')) === ''));
        $invalidHashFields = array_values(array_filter([
            'smoke_hash',
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ], static fn (string $field): bool => preg_match('/^[a-f0-9]{64}$/', (string) ($smoke[$field] ?? '')) !== 1));
        $forbiddenFlags = array_values(array_filter(['self_programming_allowed', 'completion_claim_promoted_without_receipt'], static fn (string $flag): bool => ($smoke[$flag] ?? false) === true));
        $readyForPersistence = (string) data_get($certification, 'status') === 'passed';

        $template = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => '<provider_run_id_from_operator_approved_real_provider_smoke>',
            'task_packet_id' => '<task_packet_id_exercised_claim_to_completion>',
            'observed_by' => '<operator_or_reviewer>',
            'approval_reason' => 'Operator approved a real provider claim-to-completion smoke and verified generated evidence.',
            'smoke_hash' => '<64_hex_smoke_hash_from_operator_approved_real_provider_smoke>',
            'operator_approval_receipt_hash' => '<64_hex_operator_approval_receipt_hash>',
            'evidence_ledger_hash' => '<64_hex_evidence_ledger_hash>',
            'work_product_manifest_hash' => '<64_hex_work_product_manifest_hash>',
            'cost_event_hash' => '<64_hex_cost_event_hash>',
            'continuation_summary_hash' => '<64_hex_continuation_summary_hash>',
            'provider_response_hash' => '<64_hex_provider_response_hash>',
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'smoke_contract' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'required_status' => 'passed',
                'required_fields' => $requiredFields,
                'required_observation_flags' => [
                    'provider_call_observed',
                    'token_spend_observed',
                    'claim_to_completion_observed',
                    'work_product_collected',
                ],
                'forbidden_flags' => ['self_programming_allowed', 'completion_claim_promoted_without_receipt'],
            ],
            'smoke_template' => $template,
            'smoke_hash_preflight' => [
                'canonical_hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'method' => 'realProviderSmokeHash',
                'missing_fields' => $missingFields,
                'invalid_hash_fields' => $invalidHashFields,
                'forbidden_flags' => $forbiddenFlags,
                'smoke_hash_matches_payload' => (bool) data_get($certification, 'smoke_hash_matches_payload', false),
            ],
            'claim_to_completion_evidence_map' => [
                'task_packet',
                'claim_lease',
                'execution_workspace',
                'provider_run_id',
                'cost_event',
                'work_product_manifest',
                'evidence_ledger_receipt',
                'continuation_summary',
                'provider_response',
                'human_approval_receipt',
            ],
            'operator_runbook_alignment' => [
                'runbook_service' => AtlasSelfConstructionRealProviderSmokeRunbookService::class,
                'covers_required_fields' => true,
                'covers_required_observation_flags' => true,
                'covers_forbidden_flags' => true,
            ],
            'safety_profile' => [
                'dossier_does_not_execute' => true,
                'provider_call_made' => false,
                'token_spend_made' => false,
                'smoke_persisted' => false,
                'completion_claim_allowed' => false,
            ],
            'machine_status' => [
                'status' => 'available',
                'ready_for_operator_execution' => true,
                'ready_for_persistence' => $readyForPersistence,
                'certification_would_pass' => $readyForPersistence,
                'certification_errors' => (array) data_get($certification, 'violations', []),
            ],
            'certification_preview' => [
                'status' => (string) data_get($certification, 'status', ''),
                'violation_count' => (int) data_get($certification, 'violation_count', 0),
                'certification_hash' => (string) data_get($certification, 'certification_hash', ''),
            ],
            'non_execution_guarantees' => [
                'real_provider_smoke_evidence_dossier_does_not_call_provider',
                'real_provider_smoke_evidence_dossier_does_not_spend_tokens',
                'real_provider_smoke_evidence_dossier_does_not_dispatch_work',
                'real_provider_smoke_evidence_dossier_does_not_persist_smoke',
                'real_provider_smoke_evidence_dossier_does_not_promote_completion',
            ],
        ];
        $payload['dossier_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['dossier_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}

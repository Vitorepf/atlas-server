<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeOfflineHarnessService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_offline_harness.v1';

    public const MODE = 'read_only_real_provider_smoke_offline_harness';

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $scenarioId = (string) ($options['scenario_id'] ?? 'operator-approved-claim-to-completion-smoke');
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'ready_for_operator_real_provider_smoke',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'dry_run_only' => true,
            'scenario' => [
                'scenario_id' => $scenarioId,
                'kind' => 'real_provider_packet_claim_to_completion',
                'objective' => 'Run one scoped task packet from claim to completion through an operator-approved real provider path and attach evidence.',
                'required_flow' => [
                    'operator_approval_before_provider_call',
                    'claim_single_task_packet',
                    'bind_execution_workspace',
                    'run_real_provider_once',
                    'collect_work_product_manifest',
                    'collect_cost_event',
                    'collect_continuation_summary',
                    'record_evidence_ledger_hash',
                    'persist_smoke_payload_through_verifier',
                ],
            ],
            'required_evidence_fields' => [
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
            ],
            'required_observation_flags' => [
                'provider_call_observed',
                'token_spend_observed',
                'claim_to_completion_observed',
                'work_product_collected',
            ],
            'forbidden_flags' => [
                'provider_called_by_atlas',
                'token_spent_by_atlas',
                'dispatch_allowed',
                'adapter_execution_allowed',
                'self_programming_allowed',
                'completion_claim_promoted_without_receipt',
            ],
            'evidence_template' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => '<operator_observed_provider_run_id>',
                'task_packet_id' => '<operator_observed_task_packet_id>',
                'observed_by' => '<operator_or_reviewer>',
                'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
                'smoke_hash' => '<operator_computed_64_hex_smoke_hash>',
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
                'provider_called_by_atlas' => false,
                'token_spent_by_atlas' => false,
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
                'completion_claim_promoted_without_receipt' => false,
            ],
            'hash_policy' => [
                'canonical_hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'canonical_hash_method' => 'realProviderSmokeHash',
                'operator_must_replace_placeholders' => true,
                'operator_must_compute_smoke_hash_after_observation' => true,
            ],
            'non_execution_guarantees' => [
                'real_provider_smoke_offline_harness_does_not_call_provider',
                'real_provider_smoke_offline_harness_does_not_spend_tokens',
                'real_provider_smoke_offline_harness_does_not_dispatch_work',
                'real_provider_smoke_offline_harness_does_not_start_processes',
                'real_provider_smoke_offline_harness_does_not_persist_smoke',
            ],
        ];
        $payload['harness_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['harness_hash']);

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

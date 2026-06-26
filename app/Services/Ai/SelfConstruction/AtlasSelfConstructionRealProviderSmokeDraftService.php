<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeDraftService
{
    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function ksortRecursive(array $value): array
    {
        $this->ksortRecursiveByReference($value);

        return $value;
    }
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_draft.v1';

    public const MODE = 'read_only_real_provider_smoke_draft';

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $input = [], array $options = []): array
    {
        $smoke = array_merge($this->template(), $input);
        $smoke['kind'] = (string) ($smoke['kind'] ?? 'real_provider_packet_claim_to_completion');
        $smoke['status'] = (string) ($smoke['status'] ?? 'passed');
        foreach (['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_promoted_without_receipt'] as $flag) {
            $smoke[$flag] = (bool) ($smoke[$flag] ?? false);
        }
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);

        $verification = (new AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService)->verify($smoke);
        $missingOperatorInputs = $this->placeholderOrMissingFields($smoke, [
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
        ]);
        $missingEvidenceHashes = array_values(array_filter([
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ], static fn (string $field): bool => preg_match('/^[a-f0-9]{64}$/', (string) ($smoke[$field] ?? '')) !== 1));
        $missingObservationFlags = array_values(array_filter([
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ], static fn (string $flag): bool => (bool) ($smoke[$flag] ?? false) !== true));
        $forbiddenFlags = array_values(array_filter([
            'provider_called_by_atlas',
            'token_spent_by_atlas',
            'dispatch_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ], static fn (string $flag): bool => (bool) ($smoke[$flag] ?? false) === true));

        $ready = $missingOperatorInputs === []
            && $missingEvidenceHashes === []
            && $missingObservationFlags === []
            && $forbiddenFlags === []
            && (string) data_get($verification, 'status') === 'verified_operator_supplied_real_provider_smoke_evidence';
        $persistRequested = (bool) ($options['persist_completion_evidence'] ?? false);
        $persistence = $persistRequested && $ready
            ? (new AtlasSelfConstructionRealProviderSmokeCertificationService)->persist($smoke)
            : [];
        $persisted = (bool) data_get($persistence, 'persisted', false);
        $persistenceBlocker = '';
        if ($persistRequested && ! $ready) {
            $persistenceBlocker = 'real_provider_smoke_draft_not_ready_for_persistence';
        } elseif ($persistRequested && ! $persisted) {
            $persistenceBlocker = (string) data_get($persistence, 'persistence_blocker', 'real_provider_smoke_persistence_failed');
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $persisted ? 'persisted' : ($ready ? 'ready_for_operator_persistence' : 'blocked_operator_or_evidence_input_required'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'smoke_payload' => $smoke,
            'smoke_hash' => (string) $smoke['smoke_hash'],
            'verification' => $verification,
            'persistence' => $persistence,
            'persistence_requested' => $persistRequested,
            'persisted' => $persisted,
            'persistence_blocker' => $persistenceBlocker,
            'smoke_path' => (string) data_get($persistence, 'smoke_path', ''),
            'missing_operator_inputs' => $missingOperatorInputs,
            'missing_evidence_hashes' => $missingEvidenceHashes,
            'missing_observation_flags' => $missingObservationFlags,
            'forbidden_flags' => $forbiddenFlags,
            'persistence_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
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
                $persistRequested ? 'real_provider_smoke_draft_persists_only_after_existing_certifier_passes' : 'real_provider_smoke_draft_does_not_persist_smoke',
                'real_provider_smoke_draft_does_not_call_provider',
                'real_provider_smoke_draft_does_not_spend_tokens',
                'real_provider_smoke_draft_does_not_dispatch_work',
                'real_provider_smoke_draft_does_not_start_processes',
                'real_provider_smoke_draft_does_not_promote_completion',
            ],
            'next_action' => $persisted
                ? 'rerun_completion_evidence_status_to_count_real_provider_smoke'
                : ($ready ? 'operator_may_persist_real_provider_smoke_through_existing_certifier' : 'operator_must_supply_real_smoke_observation_evidence'),
        ];
        $payload['draft_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function template(): array
    {
        return [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => '<operator_observed_provider_run_id>',
            'task_packet_id' => '<operator_observed_task_packet_id>',
            'observed_by' => '<operator_or_reviewer>',
            'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
            'smoke_hash' => '',
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
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];
    }

    /** @param list<string> $fields */
    private function placeholderOrMissingFields(array $payload, array $fields): array
    {
        return array_values(array_filter($fields, static function (string $field) use ($payload): bool {
            $value = trim((string) ($payload[$field] ?? ''));

            if ($value === '' || str_starts_with($value, '<') || str_starts_with($value, '__')) {
                return true;
            }

            $normalized = strtolower($value);
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
        }));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['draft_hash'], $payload['verification']['verified_at'], $payload['persistence']['certified_at']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}

<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_evidence_verifier.v1';

    public const MODE = 'read_only_real_provider_smoke_evidence_verifier';

    /** @return array<string, mixed> */
    public function verify(array $payload): array
    {
        $certification = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($payload);
        $violations = (array) data_get($certification, 'violations', []);

        foreach (['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed'] as $flag) {
            if ((bool) ($payload[$flag] ?? false) === true) {
                $violations[] = ['code' => 'atlas_runtime_flag_forbidden_in_operator_smoke', 'flag' => $flag];
            }
        }
        foreach (['provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value === '' || str_starts_with($value, '<')) {
                $violations[] = ['code' => 'operator_smoke_placeholder_or_missing', 'field' => $field];
            }
        }
        if (! (bool) ($payload['operator_supplied_evidence'] ?? false)) {
            $violations[] = ['code' => 'operator_supplied_evidence_ack_missing'];
        }
        if (! (bool) ($payload['real_provider_run_observed_by_operator'] ?? false)) {
            $violations[] = ['code' => 'real_provider_operator_observation_ack_missing'];
        }

        $status = $violations === [] ? 'verified_operator_supplied_real_provider_smoke_evidence' : 'blocked';
        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'certification_status' => (string) data_get($certification, 'status'),
            'smoke_hash' => (string) ($payload['smoke_hash'] ?? ''),
            'expected_smoke_hash' => (string) data_get($certification, 'expected_smoke_hash'),
            'smoke_hash_matches_payload' => (bool) data_get($certification, 'smoke_hash_matches_payload', false),
            'violation_count' => count($violations),
            'violations' => $violations,
            'completion_criterion_green' => $status === 'verified_operator_supplied_real_provider_smoke_evidence',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $result['verification_hash'] = $this->stableHash($result);

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['verification_hash']);

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

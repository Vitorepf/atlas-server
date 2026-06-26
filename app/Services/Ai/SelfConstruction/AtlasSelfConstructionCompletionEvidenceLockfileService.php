<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionEvidenceLockfileService
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
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_evidence_lockfile.v1';

    public const MODE = 'read_only_completion_evidence_lockfile';

    /** @return array<string, mixed> */
    public function build(array $evidence): array
    {
        $lock = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'evidence_hashes' => [
                'bundle_hash' => (string) data_get($evidence, 'bundle_identity.bundle_hash', data_get($evidence, 'bundle_hash', '')),
                'completion_audit_hash' => (string) data_get($evidence, 'completion_audit_hash', ''),
                'runtime_gap_matrix_hash' => (string) data_get($evidence, 'runtime_gap_matrix_hash', ''),
                'human_receipt_hash' => (string) data_get($evidence, 'receipt_hash', ''),
                'real_provider_smoke_hash' => (string) data_get($evidence, 'smoke_hash', ''),
                'release_dossier_hash' => (string) data_get($evidence, 'release_dossier_hash', ''),
                'replay_diff_hash' => (string) data_get($evidence, 'replay_diff_hash', ''),
            ],
            'blockers' => (array) data_get($evidence, 'failed_criteria', data_get($evidence, 'blockers', [])),
            'safety_flags' => [
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'persistence_allowed' => false,
            'non_execution_guarantees' => [
                'completion_evidence_lockfile_does_not_persist',
                'completion_evidence_lockfile_does_not_call_provider',
                'completion_evidence_lockfile_does_not_promote_completion',
            ],
        ];
        $lock['lockfile_hash'] = $this->stableHash($lock);

        return $lock;
    }

    /** @return array<string, mixed> */
    public function verify(array $lockfile, array $evidence): array
    {
        $expected = $this->build($evidence);
        $violations = [];
        if ((string) ($lockfile['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            $violations[] = ['code' => 'lockfile_schema_invalid'];
        }
        if ((array) ($lockfile['evidence_hashes'] ?? []) !== (array) ($expected['evidence_hashes'] ?? [])) {
            $violations[] = ['code' => 'lockfile_evidence_hashes_mismatch'];
        }
        if ((array) ($lockfile['blockers'] ?? []) !== (array) ($expected['blockers'] ?? [])) {
            $violations[] = ['code' => 'lockfile_blockers_mismatch'];
        }
        if ((string) ($lockfile['lockfile_hash'] ?? '') !== $this->stableHash($lockfile)) {
            $violations[] = ['code' => 'lockfile_hash_mismatch'];
        }

        $status = $violations === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION.'.verification',
            'mode' => 'read_only_completion_evidence_lockfile_verification',
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'violation_count' => count($violations),
            'violations' => $violations,
            'lockfile_hash' => (string) ($lockfile['lockfile_hash'] ?? ''),
            'expected_lockfile_hash' => (string) ($expected['lockfile_hash'] ?? ''),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['verified_at'], $payload['lockfile_hash'], $payload['verification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}

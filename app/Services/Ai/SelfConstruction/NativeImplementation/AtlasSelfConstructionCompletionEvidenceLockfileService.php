<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionEvidenceLockfileService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_evidence_lockfile.v1';

    public const MODE = 'read_only_completion_evidence_lockfile';

    /** @return array<string, mixed> */
    public function build(array $evidence): array
    {
        // AC4: redact raw prompt, trace and provider payload fields — never leak.
        $redacted = $this->redactRawPayloads($evidence);

        $lock = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'evidence_hashes' => [
                'bundle_hash' => (string) data_get($redacted, 'bundle_identity.bundle_hash', data_get($redacted, 'bundle_hash', '')),
                'completion_audit_hash' => (string) data_get($redacted, 'completion_audit_hash', ''),
                'runtime_gap_matrix_hash' => (string) data_get($redacted, 'runtime_gap_matrix_hash', ''),
                'human_receipt_hash' => (string) data_get($redacted, 'receipt_hash', ''),
                'real_provider_smoke_hash' => (string) data_get($redacted, 'smoke_hash', ''),
                'release_dossier_hash' => (string) data_get($redacted, 'release_dossier_hash', ''),
                'replay_diff_hash' => (string) data_get($redacted, 'replay_diff_hash', ''),
            ],
            'blockers' => (array) data_get($redacted, 'failed_criteria', data_get($redacted, 'blockers', [])),
            'safety_flags' => [
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'persistence_allowed' => false,
            'redacted_fields' => $this->redactedFieldList(),
            'non_execution_guarantees' => [
                'completion_evidence_lockfile_does_not_persist',
                'completion_evidence_lockfile_does_not_call_provider',
                'completion_evidence_lockfile_does_not_promote_completion',
                'completion_evidence_lockfile_redacts_raw_payloads',
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

        // AC2: verify required evidence fields are present and not modified.
        $requiredFields = $this->requiredEvidenceFields();
        $lockfileHashes = (array) ($lockfile['evidence_hashes'] ?? []);
        foreach ($requiredFields as $field) {
            $value = (string) ($lockfileHashes[$field] ?? '');
            if ($value === '') {
                $violations[] = ['code' => 'lockfile_missing_required_evidence_field', 'field' => $field];
            } elseif ($value !== (string) ($expected['evidence_hashes'][$field] ?? '')) {
                $violations[] = ['code' => 'lockfile_evidence_field_modified', 'field' => $field];
            }
        }

        // AC4: verify no raw payload fields leaked into the lockfile.
        $rawPayloadKeys = $this->redactedFieldList();
        foreach ($rawPayloadKeys as $key) {
            if (array_key_exists($key, $lockfile)) {
                $violations[] = ['code' => 'lockfile_raw_payload_leaked', 'field' => $key];
            }
        }

        // Safety-flags contract: the lockfile promises non-execution.
        $expectedSafety = (array) ($expected['safety_flags'] ?? []);
        $actualSafety = (array) ($lockfile['safety_flags'] ?? []);
        if ($expectedSafety !== $actualSafety) {
            $violations[] = ['code' => 'lockfile_safety_flags_mismatch', 'expected' => $expectedSafety, 'actual' => $actualSafety];
        }
        $persistAllowed = (bool) ($lockfile['persistence_allowed'] ?? true);
        if ($persistAllowed !== false) {
            $violations[] = ['code' => 'lockfile_safety_flags_mismatch', 'reason' => 'persistence_allowed_not_false'];
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
        // AC3: volatile fields (generated_at, verified_at) are excluded from the hash
        // so they don't change the substantive evidence hash.
        unset($payload['generated_at'], $payload['verified_at'], $payload['lockfile_hash'], $payload['verification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private function requiredEvidenceFields(): array
    {
        return [
            'bundle_hash',
            'completion_audit_hash',
            'runtime_gap_matrix_hash',
            'human_receipt_hash',
            'real_provider_smoke_hash',
            'release_dossier_hash',
            'replay_diff_hash',
        ];
    }

    /** @return list<string> */
    private function redactedFieldList(): array
    {
        return [
            'raw_prompt',
            'raw_trace',
            'raw_provider_payload',
            'provider_request_body',
            'provider_response_body',
            'prompt_text',
            'trace_data',
        ];
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function redactRawPayloads(array $evidence): array
    {
        foreach ($this->redactedFieldList() as $key) {
            unset($evidence[$key]);
        }

        return $evidence;
    }

    /** @param array<string, mixed> $value */
}

<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionRealProviderSmokeCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_certification.v1';

    public const MODE = 'read_only_real_provider_smoke_certification';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-construction/os-completion/real-provider-smokes';

    /** @return array<string, mixed> */
    public function certify(array $smoke = []): array
    {
        if ($smoke === []) {
            $smoke = $this->latestSmoke();
        }

        $kind = (string) ($smoke['kind'] ?? '');
        $status = (string) ($smoke['status'] ?? '');
        $smokeHash = (string) ($smoke['smoke_hash'] ?? '');
        $expectedSmokeHash = $this->hashes()->realProviderSmokeHash($smoke);
        $operatorReceiptHash = (string) ($smoke['operator_approval_receipt_hash'] ?? '');
        $evidenceLedgerHash = (string) ($smoke['evidence_ledger_hash'] ?? '');
        $providerRunId = trim((string) ($smoke['provider_run_id'] ?? ''));
        $taskPacketId = trim((string) ($smoke['task_packet_id'] ?? ''));
        $observedBy = trim((string) ($smoke['observed_by'] ?? ''));
        $approvalReason = trim((string) ($smoke['approval_reason'] ?? ''));

        $violations = [];
        if ($kind !== 'real_provider_packet_claim_to_completion') {
            $violations[] = ['code' => 'smoke_kind_invalid_or_missing'];
        }
        if ($status !== 'passed') {
            $violations[] = ['code' => 'smoke_status_not_passed'];
        }
        foreach ([
            'smoke_hash' => $smokeHash,
            'operator_approval_receipt_hash' => $operatorReceiptHash,
            'evidence_ledger_hash' => $evidenceLedgerHash,
            'work_product_manifest_hash' => (string) ($smoke['work_product_manifest_hash'] ?? ''),
            'cost_event_hash' => (string) ($smoke['cost_event_hash'] ?? ''),
            'continuation_summary_hash' => (string) ($smoke['continuation_summary_hash'] ?? ''),
            'provider_response_hash' => (string) ($smoke['provider_response_hash'] ?? ''),
        ] as $field => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $violations[] = ['code' => 'required_hash_invalid_or_missing', 'field' => $field];
            }
        }
        if ($smokeHash !== $expectedSmokeHash) {
            $violations[] = ['code' => 'smoke_hash_mismatch'];
        }
        foreach ([
            'provider_run_id' => $providerRunId,
            'task_packet_id' => $taskPacketId,
            'observed_by' => $observedBy,
            'approval_reason' => $approvalReason,
        ] as $field => $value) {
            if ($value === '') {
                $violations[] = ['code' => 'required_real_smoke_field_missing', 'field' => $field];
            }
            if ($value !== '' && str_starts_with($value, '<')) {
                $violations[] = ['code' => 'required_real_smoke_field_placeholder', 'field' => $field];
            }
        }
        foreach (['provider_call_observed', 'token_spend_observed', 'claim_to_completion_observed', 'work_product_collected'] as $flag) {
            if (($smoke[$flag] ?? false) !== true) {
                $violations[] = ['code' => 'required_real_smoke_observation_missing', 'flag' => $flag];
            }
        }
        foreach (['operator_supplied_evidence', 'real_provider_run_observed_by_operator'] as $flag) {
            if (($smoke[$flag] ?? false) !== true) {
                $violations[] = ['code' => 'required_operator_real_smoke_ack_missing', 'flag' => $flag];
            }
        }
        foreach (['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed'] as $flag) {
            if (($smoke[$flag] ?? false) === true) {
                $violations[] = ['code' => 'atlas_runtime_flag_forbidden_in_operator_smoke', 'flag' => $flag];
            }
        }
        foreach (['self_programming_allowed', 'completion_claim_promoted_without_receipt'] as $flag) {
            if (($smoke[$flag] ?? false) === true) {
                $violations[] = ['code' => 'forbidden_runtime_flag_true', 'flag' => $flag];
            }
        }

        $passed = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $passed ? 'passed' : 'blocked_missing_real_provider_smoke',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'kind' => $kind,
            'smoke_hash' => $smokeHash,
            'expected_smoke_hash' => $expectedSmokeHash,
            'smoke_hash_matches_payload' => $smokeHash === $expectedSmokeHash,
            'operator_approval_receipt_hash' => $operatorReceiptHash,
            'evidence_ledger_hash' => $evidenceLedgerHash,
            'provider_run_id' => $providerRunId,
            'task_packet_id' => $taskPacketId,
            'observed_by' => $observedBy,
            'approval_reason_present' => $approvalReason !== '',
            'evidence_hashes' => [
                'work_product_manifest_hash' => (string) ($smoke['work_product_manifest_hash'] ?? ''),
                'cost_event_hash' => (string) ($smoke['cost_event_hash'] ?? ''),
                'continuation_summary_hash' => (string) ($smoke['continuation_summary_hash'] ?? ''),
                'provider_response_hash' => (string) ($smoke['provider_response_hash'] ?? ''),
            ],
            'observations' => [
                'provider_call_observed' => (bool) ($smoke['provider_call_observed'] ?? false),
                'token_spend_observed' => (bool) ($smoke['token_spend_observed'] ?? false),
                'claim_to_completion_observed' => (bool) ($smoke['claim_to_completion_observed'] ?? false),
                'work_product_collected' => (bool) ($smoke['work_product_collected'] ?? false),
            ],
            'operator_acknowledgements' => [
                'operator_supplied_evidence' => (bool) ($smoke['operator_supplied_evidence'] ?? false),
                'real_provider_run_observed_by_operator' => (bool) ($smoke['real_provider_run_observed_by_operator'] ?? false),
            ],
            'violations' => $violations,
            'violation_count' => count($violations),
            'completion_criterion_green' => $passed,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
            'next_action' => $passed
                ? 'allow_completion_audit_to_count_real_provider_smoke'
                : 'run_operator_approved_real_provider_smoke_and_attach_receipts',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function persist(array $smoke): array
    {
        $certification = $this->certify($smoke);
        if ((string) $certification['status'] !== 'passed') {
            return $certification + [
                'persisted' => false,
                'persistence_blocker' => 'real_provider_smoke_certification_failed',
            ];
        }

        $smokeHash = (string) $certification['smoke_hash'];
        $path = self::STORAGE_PREFIX.'/'.$smokeHash.'.json';
        $stored = $smoke + [
            'schema_version' => self::SCHEMA_VERSION,
            'persisted_at' => CarbonImmutable::now()->toIso8601String(),
        ];
        Storage::disk(self::STORAGE_DISK)->put($path, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->appendRegistry([
            'kind' => (string) $certification['kind'],
            'smoke_hash' => $smokeHash,
            'path' => $path,
            'persisted_at' => (string) $stored['persisted_at'],
        ]);

        return $certification + [
            'persisted' => true,
            'smoke_path' => $path,
        ];
    }

    /** @return array<string, mixed> */
    private function latestSmoke(): array
    {
        $registry = $this->registry();
        $latest = end($registry);
        if (! is_array($latest)) {
            return [];
        }

        $path = (string) ($latest['path'] ?? '');
        if ($path === '' || ! Storage::disk(self::STORAGE_DISK)->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk(self::STORAGE_DISK)->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string, mixed>> */
    private function registry(): array
    {
        $path = self::STORAGE_PREFIX.'/registry.json';
        if (! Storage::disk(self::STORAGE_DISK)->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk(self::STORAGE_DISK)->get($path), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<string, mixed> $entry */
    private function appendRegistry(array $entry): void
    {
        $registry = array_values(array_filter(
            $this->registry(),
            static fn (array $candidate): bool => (string) ($candidate['smoke_hash'] ?? '') !== (string) $entry['smoke_hash'],
        ));
        $registry[] = $entry;
        Storage::disk(self::STORAGE_DISK)->put(self::STORAGE_PREFIX.'/registry.json', json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function hashes(): AtlasSelfConstructionCompletionEvidenceHashService
    {
        return new AtlasSelfConstructionCompletionEvidenceHashService;
    }

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

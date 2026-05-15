<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionAdapterExecutionRuntimeGraduationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.adapter_execution_runtime_graduation.v1';

    public const MODE = 'read_only_adapter_execution_runtime_graduation';

    public function __construct(
        private readonly AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService $boundary = new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $boundary = $this->boundary->certify($options);
        $runtimeFlagsFalse = $this->runtimeFlagsFalse($boundary);
        $boundaryHash = (string) data_get($boundary, 'certification_hash', '');
        $violations = [];

        if (($boundary['status'] ?? '') !== 'available') {
            $violations[] = ['code' => 'adapter_execution_boundary_not_available'];
        }
        if (! $runtimeFlagsFalse) {
            $violations[] = ['code' => 'adapter_execution_runtime_flag_true'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', $boundaryHash) !== 1) {
            $violations[] = ['code' => 'adapter_execution_boundary_hash_missing'];
        }

        $candidate = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $candidate ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_gap' => 'adapter_execution_runtime',
            'runtime_y_candidate' => $candidate,
            'runtime_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_process_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'evidence_hashes' => [
                'adapter_execution_boundary_certification_hash' => $boundaryHash,
            ],
            'boundary_status' => (string) ($boundary['status'] ?? 'unknown'),
            'violations' => $violations,
            'violation_count' => count($violations),
            'next_action' => $candidate
                ? 'eligible_for_signed_adapter_execution_runtime_promotion_receipt'
                : 'repair_adapter_execution_runtime_graduation_blockers',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function runtimeFlagsFalse(array $payload): bool
    {
        foreach (['adapter_invocation_allowed', 'adapter_execution_allowed', 'provider_process_call_allowed', 'provider_call_allowed', 'token_spend_allowed', 'dispatch_allowed', 'process_started', 'provider_started', 'external_process_started', 'self_programming_allowed'] as $flag) {
            if ((bool) data_get($payload, $flag, false) !== false) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

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

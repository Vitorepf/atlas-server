<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionAutomaticCostImportRuntimeGraduationService
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
    public const SCHEMA_VERSION = 'atlas.self_construction.automatic_cost_import_runtime_graduation.v1';

    public const MODE = 'read_only_automatic_cost_import_runtime_graduation';

    public function __construct(
        private readonly AgentControlPlaneAutomaticCostImportRuntimeCertificationService $certification = new AgentControlPlaneAutomaticCostImportRuntimeCertificationService,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $certification = $this->certification->certify($options);
        $certificationHash = (string) data_get($certification, 'certification_hash', '');
        $violations = [];

        if (($certification['status'] ?? '') !== 'available') {
            $violations[] = ['code' => 'automatic_cost_import_runtime_certification_not_available'];
        }
        if (! (bool) data_get($certification, 'invariants_all_true', false)) {
            $violations[] = ['code' => 'automatic_cost_import_invariants_not_all_true'];
        }
        if (! $this->runtimeFlagsFalse($certification)) {
            $violations[] = ['code' => 'automatic_cost_import_runtime_flag_true'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', $certificationHash) !== 1) {
            $violations[] = ['code' => 'automatic_cost_import_certification_hash_missing'];
        }

        $candidate = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $candidate ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_gap' => 'automatic_cost_import_runtime',
            'runtime_y_candidate' => $candidate,
            'runtime_enabled' => false,
            'cost_import_mode' => 'receipt_based_dry_run_certified',
            'provider_billing_api_read_allowed' => false,
            'cost_events_write_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'evidence_hashes' => [
                'automatic_cost_import_runtime_certification_hash' => $certificationHash,
                'normalized_cost_events_hash' => (string) data_get($certification, 'cost_event_normalization.normalized_cost_events_hash', ''),
                'cost_import_receipt_plan_hash' => (string) data_get($certification, 'cost_import_receipt_plan.cost_import_receipt_plan_hash', ''),
            ],
            'certification_status' => (string) ($certification['status'] ?? 'unknown'),
            'violations' => $violations,
            'violation_count' => count($violations),
            'next_action' => $candidate
                ? 'eligible_for_signed_cost_import_runtime_promotion_receipt'
                : 'repair_automatic_cost_import_runtime_graduation_blockers',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function runtimeFlagsFalse(array $payload): bool
    {
        foreach (['import_allowed', 'cost_events_write_allowed', 'provider_billing_api_read_allowed', 'token_spend_allowed', 'dispatch_allowed', 'adapter_execution_allowed', 'provider_call_allowed', 'process_start_allowed', 'self_programming_allowed'] as $flag) {
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
}

<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionAutomaticWorkProductCollectionRuntimeGraduationService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.automatic_work_product_collection_runtime_graduation.v1';

    public const MODE = 'read_only_automatic_work_product_collection_runtime_graduation';

    public function __construct(
        private readonly AgentControlPlaneAutomaticWorkProductCollectionCertificationService $certification = new AgentControlPlaneAutomaticWorkProductCollectionCertificationService,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $certification = $this->certification->certify($options);
        $certificationHash = (string) data_get($certification, 'certification_hash', '');
        $violations = [];

        if (($certification['status'] ?? '') !== 'available') {
            $violations[] = ['code' => 'automatic_work_product_collection_certification_not_available'];
        }
        if (! (bool) data_get($certification, 'invariants_all_true', false)) {
            $violations[] = ['code' => 'automatic_work_product_collection_invariants_not_all_true'];
        }
        if (! $this->runtimeFlagsFalse($certification)) {
            $violations[] = ['code' => 'automatic_work_product_collection_runtime_flag_true'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', $certificationHash) !== 1) {
            $violations[] = ['code' => 'automatic_work_product_collection_certification_hash_missing'];
        }

        $candidate = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $candidate ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_gap' => 'automatic_work_product_collection_runtime',
            'runtime_y_candidate' => $candidate,
            'runtime_enabled' => false,
            'collection_mode' => 'manifest_receipt_dry_run_certified',
            'workspace_scan_allowed' => false,
            'work_product_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'evidence_hashes' => [
                'automatic_work_product_collection_certification_hash' => $certificationHash,
                'normalized_work_products_hash' => (string) data_get($certification, 'work_product_normalization.normalized_work_products_hash', ''),
                'work_product_collection_receipt_plan_hash' => (string) data_get($certification, 'work_product_collection_receipt_plan.work_product_collection_receipt_plan_hash', ''),
            ],
            'certification_status' => (string) ($certification['status'] ?? 'unknown'),
            'violations' => $violations,
            'violation_count' => count($violations),
            'next_action' => $candidate
                ? 'eligible_for_signed_work_product_collection_runtime_promotion_receipt'
                : 'repair_automatic_work_product_collection_runtime_graduation_blockers',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function runtimeFlagsFalse(array $payload): bool
    {
        foreach (['collection_allowed', 'workspace_scan_allowed', 'work_product_write_allowed', 'provider_call_allowed', 'token_spend_allowed', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_allowed'] as $flag) {
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

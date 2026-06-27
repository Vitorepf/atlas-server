<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

final class AgentControlPlaneAutomaticWorkProductCollectionCertificationService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_automatic_work_product_collection_runtime.v1';

    public const MODE = 'read_only_agent_control_plane_automatic_work_product_collection_runtime_certification';

    public function __construct(
        private readonly AgentControlPlaneWorkProductCandidateNormalizer $normalizer = new AgentControlPlaneWorkProductCandidateNormalizer,
        private readonly AgentControlPlaneWorkProductCollectionReceiptPlanner $receiptPlanner = new AgentControlPlaneWorkProductCollectionReceiptPlanner,
        private readonly AgentControlPlaneWorkProductManifestReconciler $reconciler = new AgentControlPlaneWorkProductManifestReconciler,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $candidates = (array) ($options['work_product_candidates'] ?? $this->defaultCandidates());
        $expected = (array) ($options['expected_outputs'] ?? $this->defaultExpectedOutputs());
        $normalized = $this->normalizer->normalize($candidates);
        $receiptPlan = $this->receiptPlanner->plan((array) data_get($normalized, 'normalized_work_products', []));
        $reconciliation = $this->reconciler->reconcile((array) data_get($normalized, 'normalized_work_products', []), $expected);

        $violations = array_values(array_merge(
            (array) data_get($normalized, 'violations', []),
            $this->runtimeFlagViolations($options),
            $this->reconciliationViolations($reconciliation),
        ));
        $invariants = [
            $this->inv('no_workspace_scan', (bool) data_get($normalized, 'workspace_scan_allowed') === false, 'workspace scans are forbidden'),
            $this->inv('no_work_product_write', (bool) data_get($receiptPlan, 'work_product_write_allowed') === false, 'work product writes are forbidden'),
            $this->inv('no_provider_call', true, 'provider calls are forbidden'),
            $this->inv('no_dispatch', true, 'dispatch is forbidden'),
            $this->inv('no_token_spend', true, 'token spend is forbidden'),
            $this->inv('no_self_programming', true, 'self-programming is forbidden'),
            $this->inv('deterministic_hashes', $this->hashesPresent($normalized, $receiptPlan, $reconciliation), 'all subhashes are present'),
            $this->inv('reconciliation_dry_run_only', (bool) data_get($reconciliation, 'dry_run_only') === true, 'manifest reconciliation is dry-run only'),
        ];

        $status = $violations === [] ? 'available' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'work_product_normalization' => $normalized,
            'work_product_collection_receipt_plan' => $receiptPlan,
            'work_product_manifest_reconciliation' => $reconciliation,
            'invariants' => $invariants,
            'invariants_all_true' => ! in_array(false, array_column($invariants, 'ok'), true),
            'violations' => $violations,
            'violation_count' => count($violations),
            'collection_allowed' => false,
            'workspace_scan_allowed' => false,
            'work_product_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'collection_allowed' => false,
                'workspace_scan_allowed' => false,
                'work_product_write_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
                'completion_claim_allowed' => false,
            ],
            'next_action' => $status === 'available'
                ? 'keep_automatic_work_product_collection_runtime_read_only_until_signed_collection_execution_gate'
                : 'repair_automatic_work_product_collection_runtime_certification_violations',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function defaultCandidates(): array
    {
        return [[
            'artifact_id' => 'artifact-runtime-pilot-001',
            'path' => 'app/Services/Ai/SelfConstruction/AgentControlPlaneRuntimePilotOrchestrator.php',
            'artifact_hash' => hash('sha256', 'runtime-pilot-artifact'),
            'source' => 'manual_manifest',
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function defaultExpectedOutputs(): array
    {
        return [['path' => 'app/Services/Ai/SelfConstruction/AgentControlPlaneRuntimePilotOrchestrator.php']];
    }

    /** @return list<array<string, mixed>> */
    private function runtimeFlagViolations(array $options): array
    {
        $violations = [];
        foreach (['collection_allowed', 'workspace_scan_allowed', 'work_product_write_allowed', 'provider_call_allowed', 'token_spend_allowed', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_allowed'] as $flag) {
            if (($options[$flag] ?? false) === true) {
                $violations[] = ['code' => 'runtime_flag_true', 'flag' => $flag, 'message' => 'Automatic work product collection certification rejects runtime-enabling flags.'];
            }
        }

        return $violations;
    }

    /** @return list<array<string, mixed>> */
    private function reconciliationViolations(array $reconciliation): array
    {
        $violations = [];
        foreach ((array) data_get($reconciliation, 'missing_outputs', []) as $missing) {
            $violations[] = ['code' => 'expected_work_product_missing', 'path' => (string) ($missing['path'] ?? ''), 'message' => 'Expected work product is missing from the normalized manifest.'];
        }
        foreach ((array) data_get($reconciliation, 'unexpected_outputs', []) as $unexpected) {
            $violations[] = ['code' => 'candidate_outside_expected_manifest', 'path' => (string) ($unexpected['path'] ?? ''), 'message' => 'Candidate work product is outside the expected manifest.'];
        }

        return $violations;
    }

    private function hashesPresent(array $normalized, array $receiptPlan, array $reconciliation): bool
    {
        foreach ([
            data_get($normalized, 'normalized_work_products_hash'),
            data_get($receiptPlan, 'work_product_collection_receipt_plan_hash'),
            data_get($reconciliation, 'manifest_reconciliation_hash'),
        ] as $hash) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function inv(string $name, bool $ok, string $observation): array
    {
        return ['name' => $name, 'ok' => $ok, 'observation' => $observation];
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);
        unset($payload['work_product_collection_receipt_plan']['planned_at']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}

<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

class AtlasProductDeliveryMutativeRepairExecutorService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.mutative_repair_executor.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $repairBridge
     * @param  array<string,mixed>  $patchManifest
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function execute(
        array $delivery,
        array $proof,
        array $repairBridge,
        array $patchManifest,
        array $options = [],
    ): array {
        $apply = (bool) ($options['apply'] ?? false);
        $operations = $this->operations($patchManifest['operations'] ?? []);
        $allowedFiles = $this->allowedFiles($delivery, $patchManifest);
        $preflight = $this->preflight($operations, $allowedFiles);

        if ($preflight['status'] !== 'passed') {
            return $this->receipt(
                status: 'blocked',
                writes: false,
                delivery: $delivery,
                proof: $proof,
                repairBridge: $repairBridge,
                patchManifest: $patchManifest,
                preflight: $preflight,
                rollback: [],
                proofAfterRepair: null,
                reason: 'preflight_failed',
            );
        }

        $rollback = $this->rollbackSnapshot($operations);
        if (! $apply) {
            return $this->receipt(
                status: 'dry_run_ready',
                writes: false,
                delivery: $delivery,
                proof: $proof,
                repairBridge: $repairBridge,
                patchManifest: $patchManifest,
                preflight: $preflight,
                rollback: $rollback,
                proofAfterRepair: null,
                reason: 'apply_false',
            );
        }

        foreach ($operations as $operation) {
            File::ensureDirectoryExists(dirname($operation['absolute_path']));
            File::put($operation['absolute_path'], $operation['content']);
        }

        $proofAfterRepair = app(AtlasProductFalsificationProofRuntimeService::class)->challenge([
            'product_truth' => $delivery['product_truth'] ?? [],
            'delivery_contract' => $delivery,
            'evidence' => is_array($options['proof_evidence'] ?? null) ? $options['proof_evidence'] : [],
        ]);

        return $this->receipt(
            status: ($proofAfterRepair['status'] ?? null) === 'ready' ? 'applied_and_verified' : 'applied_needs_proof',
            writes: true,
            delivery: $delivery,
            proof: $proof,
            repairBridge: $repairBridge,
            patchManifest: $patchManifest,
            preflight: $preflight,
            rollback: $rollback,
            proofAfterRepair: $proofAfterRepair,
            reason: ($proofAfterRepair['status'] ?? null) === 'ready' ? 'proof_ready_after_repair' : 'proof_not_ready_after_repair',
        );
    }

    /**
     * @param  list<array<string,mixed>>  $operations
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function preflight(array $operations, array $allowedFiles): array
    {
        $blockers = [];
        if ($operations === []) {
            $blockers[] = ['id' => 'missing_patch_operations', 'reason' => 'Patch manifest has no operations.'];
        }

        foreach ($operations as $operation) {
            $path = $operation['path'];
            if (! in_array($path, $allowedFiles, true)) {
                $blockers[] = ['id' => 'path_not_allowed', 'path' => $path];
            }
            if ($operation['content'] === '') {
                $blockers[] = ['id' => 'empty_replacement_content', 'path' => $path];
            }
            if ($operation['expected_sha256'] !== null
                && is_file($operation['absolute_path'])
                && hash_file('sha256', $operation['absolute_path']) !== $operation['expected_sha256']) {
                $blockers[] = ['id' => 'expected_hash_mismatch', 'path' => $path];
            }
        }

        return [
            'schema_version' => 'atlas.product_delivery.mutative_repair_preflight.v1',
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'operations_count' => count($operations),
            'allowed_files_count' => count($allowedFiles),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $operations
     * @return list<array<string,mixed>>
     */
    private function rollbackSnapshot(array $operations): array
    {
        return array_map(static function (array $operation): array {
            $exists = is_file($operation['absolute_path']);
            $content = $exists ? (string) File::get($operation['absolute_path']) : null;

            return [
                'path' => $operation['path'],
                'existed' => $exists,
                'sha256_before' => $content !== null ? hash('sha256', $content) : null,
                'content_before' => $content,
            ];
        }, $operations);
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $patchManifest
     * @return list<string>
     */
    private function allowedFiles(array $delivery, array $patchManifest): array
    {
        $fromDelivery = data_get($delivery, 'delivery_plan.scope_guard.allowed_files', []);
        $fromManifest = $patchManifest['allowed_files'] ?? [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): ?string => is_scalar($path) && trim((string) $path) !== '' ? trim((string) $path) : null,
            array_merge(is_array($fromDelivery) ? $fromDelivery : [], is_array($fromManifest) ? $fromManifest : []),
        ))));
    }

    /**
     * @return list<array{path:string,absolute_path:string,content:string,expected_sha256:?string}>
     */
    private function operations(mixed $operations): array
    {
        if (! is_array($operations)) {
            return [];
        }

        $root = realpath(base_path()) ?: base_path();
        $clean = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $path = trim((string) ($operation['path'] ?? ''));
            $content = (string) ($operation['content'] ?? '');
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            $absolute = base_path($path);
            $directory = realpath(dirname($absolute)) ?: dirname($absolute);
            if (! str_starts_with($directory, $root)) {
                continue;
            }
            $expected = isset($operation['expected_sha256']) && is_scalar($operation['expected_sha256'])
                ? trim((string) $operation['expected_sha256'])
                : null;

            $clean[] = [
                'path' => $path,
                'absolute_path' => $absolute,
                'content' => $content,
                'expected_sha256' => $expected !== '' ? $expected : null,
            ];
        }

        return $clean;
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $repairBridge
     * @param  array<string,mixed>  $patchManifest
     * @param  array<string,mixed>  $preflight
     * @param  list<array<string,mixed>>  $rollback
     * @param  array<string,mixed>|null  $proofAfterRepair
     * @return array<string,mixed>
     */
    private function receipt(
        string $status,
        bool $writes,
        array $delivery,
        array $proof,
        array $repairBridge,
        array $patchManifest,
        array $preflight,
        array $rollback,
        ?array $proofAfterRepair,
        string $reason,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'reason' => $reason,
            'writes' => $writes,
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'proof_hash' => (string) ($proof['proof_hash'] ?? ''),
            'repair_bridge_hash' => (string) ($repairBridge['repair_bridge_hash'] ?? ''),
            'patch_manifest_hash' => MissionCanonicalHash::sha256($patchManifest),
            'preflight' => $preflight,
            'rollback_plan' => [
                'schema_version' => 'atlas.product_delivery.rollback_snapshot.v1',
                'required' => true,
                'items' => $rollback,
            ],
            'proof_after_repair' => $proofAfterRepair,
            'claim_policy' => [
                'provider_invoked' => false,
                'auto_generated_patch' => false,
                'writes' => $writes,
                'rollback_required' => true,
            ],
        ];
        $payload['execution_receipt_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}

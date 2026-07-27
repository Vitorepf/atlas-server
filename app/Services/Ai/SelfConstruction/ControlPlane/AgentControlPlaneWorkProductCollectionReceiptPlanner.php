<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AgentControlPlaneWorkProductCollectionReceiptPlanner
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;

    /**
     * @param  list<array<string, mixed>>  $workProducts
     * @return array<string, mixed>
     */
    public function plan(array $workProducts): array
    {
        $receipts = [];
        foreach ($workProducts as $product) {
            $receipts[] = [
                'receipt_kind' => 'work_product_collection_dry_run_receipt',
                'receipt_id' => hash('sha256', (string) ($product['artifact_id'] ?? '').'|'.(string) ($product['path'] ?? '').'|'.(string) ($product['artifact_hash'] ?? '')),
                'artifact_id' => (string) ($product['artifact_id'] ?? ''),
                'path' => (string) ($product['path'] ?? ''),
                'artifact_hash' => (string) ($product['artifact_hash'] ?? ''),
                'planned_outcome' => 'work_product_collection_planned_not_written',
                'write_allowed' => false,
            ];
        }

        $payload = [
            'status' => 'work_product_collection_receipt_plan_ready',
            'planned_at' => CarbonImmutable::now()->toIso8601String(),
            'receipt_count' => count($receipts),
            'receipts' => $receipts,
            'ledger_write_allowed' => false,
            'work_product_write_allowed' => false,
            'receipt_persistence_allowed' => false,
        ];
        $payload['work_product_collection_receipt_plan_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['planned_at'], $payload['work_product_collection_receipt_plan_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursiveByReference($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}

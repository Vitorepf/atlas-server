<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
final class AgentControlPlaneWorkProductManifestReconciler
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $normalizedProducts
     * @param  list<array<string, mixed>>  $expectedOutputs
     * @return array<string, mixed>
     */
    public function reconcile(array $normalizedProducts, array $expectedOutputs): array
    {
        $invalidPathCount = 0;

        $observedPaths = [];
        $duplicateObservedPaths = [];
        foreach ($normalizedProducts as $product) {
            $path = (string) ($product['path'] ?? '');
            if ($path === '') {
                $invalidPathCount++;

                continue;
            }
            if (isset($observedPaths[$path])) {
                $duplicateObservedPaths[$path] = true;

                continue;
            }
            $observedPaths[$path] = true;
        }

        $expectedPaths = [];
        foreach ($expectedOutputs as $output) {
            $path = (string) ($output['path'] ?? '');
            if ($path === '') {
                $invalidPathCount++;

                continue;
            }
            $expectedPaths[$path] = true;
        }

        $missing = [];
        foreach (array_keys($expectedPaths) as $path) {
            if (! isset($observedPaths[$path])) {
                $missing[] = ['path' => $path, 'reason' => 'expected_work_product_missing'];
            }
        }

        $unexpected = [];
        foreach (array_keys($observedPaths) as $path) {
            if ($expectedPaths !== [] && ! isset($expectedPaths[$path])) {
                $unexpected[] = ['path' => $path, 'reason' => 'candidate_outside_expected_manifest'];
            }
        }

        $hasGaps = $missing !== [] || $unexpected !== [];

        $payload = [
            'status' => $hasGaps ? 'manifest_reconciliation_has_gaps' : 'manifest_reconciliation_clear',
            'next_action' => $hasGaps ? 'repair_manifest_before_collection' : 'collection_may_proceed',
            'expected_output_count' => count($expectedPaths),
            'observed_output_count' => count($observedPaths),
            'invalid_path_count' => $invalidPathCount,
            'duplicate_observed_paths' => array_values(array_keys($duplicateObservedPaths)),
            'duplicate_observed_path_count' => count($duplicateObservedPaths),
            'missing_outputs' => $missing,
            'missing_count' => count($missing),
            'unexpected_outputs' => $unexpected,
            'unexpected_count' => count($unexpected),
            'collection_allowed' => false,
            'workspace_scan_allowed' => false,
            'work_product_write_allowed' => false,
            'dry_run_only' => true,
        ];
        $payload['manifest_reconciliation_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['manifest_reconciliation_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}

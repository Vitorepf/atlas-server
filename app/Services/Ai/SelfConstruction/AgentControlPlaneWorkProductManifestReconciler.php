<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
final class AgentControlPlaneWorkProductManifestReconciler
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
    /**
     * @param  list<array<string, mixed>>  $normalizedProducts
     * @param  list<array<string, mixed>>  $expectedOutputs
     * @return array<string, mixed>
     */
    public function reconcile(array $normalizedProducts, array $expectedOutputs): array
    {
        $observedPaths = [];
        foreach ($normalizedProducts as $product) {
            $observedPaths[(string) ($product['path'] ?? '')] = true;
        }

        $expectedPaths = [];
        foreach ($expectedOutputs as $output) {
            $expectedPaths[(string) ($output['path'] ?? '')] = true;
        }

        $missing = [];
        foreach (array_keys($expectedPaths) as $path) {
            if ($path !== '' && ! isset($observedPaths[$path])) {
                $missing[] = ['path' => $path, 'reason' => 'expected_work_product_missing'];
            }
        }

        $unexpected = [];
        foreach (array_keys($observedPaths) as $path) {
            if ($path !== '' && $expectedPaths !== [] && ! isset($expectedPaths[$path])) {
                $unexpected[] = ['path' => $path, 'reason' => 'candidate_outside_expected_manifest'];
            }
        }

        $payload = [
            'status' => $missing === [] && $unexpected === [] ? 'manifest_reconciliation_clear' : 'manifest_reconciliation_has_gaps',
            'expected_output_count' => count($expectedPaths),
            'observed_output_count' => count($observedPaths),
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

<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;

/**
 * Imports a runner-produced native bundle and binds every byte to the manifest.
 * Single JSON imports remain available only for harness/diagnostic/legacy runs.
 */
final class NativeExecutionBundleImporter
{
    /** @return array<string, mixed> */
    public function import(string $runId, string $suiteId, string $source): array
    {
        $manifest = NativeExecutionManifest::load($runId);
        $tier = (string) $manifest->data['claim_tier'];
        if (in_array($tier, [ClaimTier::PRODUCTION, ClaimTier::PUBLIC], true)) {
            return $this->importProductionBundle($runId, $source, $manifest);
        }

        if (! is_file($source)) {
            throw new RuntimeException("rivals_legacy_results_file_not_found:{$source}");
        }
        $destDir = RunPaths::runDir($runId).'/external_results';
        RunPaths::ensureDir($destDir);
        $dest = $destDir.'/'.$suiteId.'.json';
        if (! copy($source, $dest)) {
            throw new RuntimeException('rivals_legacy_results_copy_failed');
        }

        return [
            'mode' => 'legacy_non_claim',
            'manifest_hash' => $manifest->hash(),
            'results_imported' => 1,
            'native_receipts_imported' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function importProductionBundle(
        string $runId,
        string $source,
        NativeExecutionManifest $manifest,
    ): array {
        if (! is_dir($source)) {
            throw new RuntimeException('rivals_production_bundle_directory_required');
        }
        $receiptDir = rtrim($source, '/').'/native_execution_receipts';
        if (! is_dir($receiptDir)) {
            throw new RuntimeException('rivals_native_receipt_directory_missing');
        }

        $expected = collect($manifest->entries())->keyBy('execution_id');
        $receiptFiles = glob($receiptDir.'/*.json') ?: [];
        $observedIds = array_map(
            fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $receiptFiles,
        );
        $missing = array_values(array_diff($expected->keys()->all(), $observedIds));
        $extra = array_values(array_diff($observedIds, $expected->keys()->all()));
        if ($missing !== [] || $extra !== []) {
            throw new RuntimeException('rivals_native_receipt_set_mismatch:missing='
                .implode(',', $missing).';extra='.implode(',', $extra));
        }

        $imported = 0;
        foreach ($receiptFiles as $receiptPath) {
            $receipt = NativeExecutionReceipt::fromArray(
                json_decode((string) file_get_contents($receiptPath), true) ?? []
            );
            $executionId = (string) $receipt->data['execution_id'];
            $entry = (array) $expected[$executionId];
            foreach ([
                'run_id' => $runId,
                'manifest_hash' => $manifest->hash(),
                'command_hash' => (string) $entry['command_hash'],
                'expected_result_path' => (string) $entry['expected_result_path'],
            ] as $field => $value) {
                if (($receipt->data[$field] ?? null) !== $value) {
                    throw new RuntimeException(
                        "rivals_native_receipt_binding_mismatch:{$executionId}:{$field}"
                    );
                }
            }

            $relativeResult = (string) $entry['expected_result_path'];
            try {
                $sourceResult = RunPaths::resolveContained($source, $relativeResult);
            } catch (\Throwable) {
                throw new RuntimeException("rivals_native_result_missing:{$executionId}");
            }
            if (! hash_equals(
                (string) $receipt->data['result_sha256'],
                hash_file('sha256', $sourceResult),
            )) {
                throw new RuntimeException("rivals_native_result_hash_mismatch:{$executionId}");
            }
            $dest = RunPaths::runDir($runId).'/'.$relativeResult;
            RunPaths::ensureDir(dirname($dest));
            if (realpath($sourceResult) !== realpath($dest) && ! copy($sourceResult, $dest)) {
                throw new RuntimeException("rivals_native_result_copy_failed:{$executionId}");
            }
            $receipt->persist();
            $imported++;
        }

        return [
            'mode' => 'manifest_bound',
            'manifest_hash' => $manifest->hash(),
            'results_imported' => $imported,
            'native_receipts_imported' => $imported,
        ];
    }
}

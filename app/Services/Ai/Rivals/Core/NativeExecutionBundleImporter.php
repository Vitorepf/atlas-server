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
        $logsImported = 0;
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
            foreach (['stdout', 'stderr'] as $stream) {
                if (($receipt->data[$stream]['present'] ?? false) !== true) {
                    continue;
                }
                $relativeLog = (string) ($receipt->data[$stream]['path'] ?? '');
                try {
                    $sourceLog = RunPaths::resolveContained($source, $relativeLog);
                } catch (\Throwable) {
                    throw new RuntimeException("rivals_native_log_missing:{$executionId}:{$stream}");
                }
                if (! hash_equals(
                    (string) $receipt->data[$stream]['sha256'],
                    hash_file('sha256', $sourceLog),
                )) {
                    throw new RuntimeException("rivals_native_log_hash_mismatch:{$executionId}:{$stream}");
                }
                $destLog = RunPaths::runDir($runId).'/'.$relativeLog;
                RunPaths::ensureDir(dirname($destLog));
                if (realpath($sourceLog) !== realpath($destLog) && ! copy($sourceLog, $destLog)) {
                    throw new RuntimeException("rivals_native_log_copy_failed:{$executionId}:{$stream}");
                }
                $logsImported++;
            }
            $receipt->persist();
            $imported++;
        }

        $attempts = $this->importAttemptHistory(
            $runId,
            $source,
            $manifest,
            $expected->all(),
        );

        return [
            'mode' => 'manifest_bound',
            'manifest_hash' => $manifest->hash(),
            'results_imported' => $imported,
            'native_receipts_imported' => $imported,
            'native_logs_imported' => $logsImported,
            'native_attempt_receipts_imported' => $attempts['receipts'],
            'native_attempt_logs_imported' => $attempts['logs'],
        ];
    }

    /**
     * Preserve failed retry attempts as immutable audit evidence without loading
     * them as the current receipt for an execution.
     *
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{receipts: int, logs: int}
     */
    private function importAttemptHistory(
        string $runId,
        string $source,
        NativeExecutionManifest $manifest,
        array $expected,
    ): array {
        $attemptRoot = rtrim($source, '/').'/native_execution_receipts/attempts';
        if (! is_dir($attemptRoot)) {
            return ['receipts' => 0, 'logs' => 0];
        }

        $receiptFiles = glob($attemptRoot.'/*/attempt-*.receipt.json') ?: [];
        $receiptsImported = 0;
        $logsImported = 0;
        foreach ($receiptFiles as $receiptPath) {
            if (is_link($attemptRoot) || is_link(dirname($receiptPath))) {
                throw new RuntimeException('rivals_native_attempt_directory_symlink_forbidden');
            }
            if (preg_match('/^(attempt-(\d{4}))\.receipt\.json$/', basename($receiptPath), $match) !== 1) {
                throw new RuntimeException('rivals_native_attempt_receipt_name_invalid');
            }
            $attemptPrefix = $match[1];
            $attemptNumber = (int) $match[2];
            $pathExecutionId = basename(dirname($receiptPath));
            $relativeReceipt = 'native_execution_receipts/attempts/'
                .$pathExecutionId.'/'.$attemptPrefix.'.receipt.json';
            $receiptPath = $this->resolveAttemptFile(
                $source,
                $relativeReceipt,
                $pathExecutionId,
                'receipt',
            );
            $receipt = NativeExecutionReceipt::fromArray(
                json_decode((string) file_get_contents($receiptPath), true) ?? [],
            );
            $executionId = (string) $receipt->data['execution_id'];
            if ($pathExecutionId !== $executionId || ! isset($expected[$executionId])) {
                throw new RuntimeException(
                    "rivals_native_attempt_execution_mismatch:{$executionId}",
                );
            }
            $entry = $expected[$executionId];
            foreach ([
                'run_id' => $runId,
                'manifest_hash' => $manifest->hash(),
                'command_hash' => (string) $entry['command_hash'],
                'expected_result_path' => (string) $entry['expected_result_path'],
                'retry_attempt' => $attemptNumber,
            ] as $field => $value) {
                if (($receipt->data[$field] ?? null) !== $value) {
                    throw new RuntimeException(
                        "rivals_native_attempt_binding_mismatch:{$executionId}:{$field}",
                    );
                }
            }
            if (($receipt->data['status'] ?? null) === 'success') {
                throw new RuntimeException(
                    "rivals_native_attempt_success_not_archivable:{$executionId}",
                );
            }

            $relativeAttemptDir = 'native_execution_receipts/attempts/'.$executionId;
            $relativeResult = $relativeAttemptDir.'/'.$attemptPrefix.'.result.json';
            if (($receipt->data['archived_result_path'] ?? null) !== $relativeResult) {
                throw new RuntimeException(
                    "rivals_native_attempt_result_path_mismatch:{$executionId}",
                );
            }
            $sourceResult = $this->resolveAttemptFile($source, $relativeResult, $executionId, 'result');
            if (! hash_equals(
                (string) $receipt->data['result_sha256'],
                (string) hash_file('sha256', $sourceResult),
            )) {
                throw new RuntimeException(
                    "rivals_native_attempt_result_hash_mismatch:{$executionId}",
                );
            }
            $this->copyAttemptFile($runId, $sourceResult, $relativeResult, $executionId, 'result');

            foreach (['stdout', 'stderr'] as $stream) {
                if (($receipt->data[$stream]['present'] ?? false) !== true) {
                    throw new RuntimeException(
                        "rivals_native_attempt_log_missing:{$executionId}:{$stream}",
                    );
                }
                $relativeLog = $relativeAttemptDir.'/'.$attemptPrefix.'.'.$stream.'.log';
                if (($receipt->data[$stream]['path'] ?? null) !== $relativeLog) {
                    throw new RuntimeException(
                        "rivals_native_attempt_log_path_mismatch:{$executionId}:{$stream}",
                    );
                }
                $sourceLog = $this->resolveAttemptFile(
                    $source,
                    $relativeLog,
                    $executionId,
                    $stream,
                );
                if (! hash_equals(
                    (string) $receipt->data[$stream]['sha256'],
                    (string) hash_file('sha256', $sourceLog),
                )) {
                    throw new RuntimeException(
                        "rivals_native_attempt_log_hash_mismatch:{$executionId}:{$stream}",
                    );
                }
                $this->copyAttemptFile(
                    $runId,
                    $sourceLog,
                    $relativeLog,
                    $executionId,
                    $stream,
                );
                $logsImported++;
            }

            $this->copyAttemptFile(
                $runId,
                $receiptPath,
                $relativeReceipt,
                $executionId,
                'receipt',
            );
            $receiptsImported++;
        }

        return ['receipts' => $receiptsImported, 'logs' => $logsImported];
    }

    private function resolveAttemptFile(
        string $source,
        string $relative,
        string $executionId,
        string $kind,
    ): string {
        try {
            return RunPaths::resolveContained($source, $relative);
        } catch (\Throwable) {
            throw new RuntimeException(
                "rivals_native_attempt_file_missing:{$executionId}:{$kind}",
            );
        }
    }

    private function copyAttemptFile(
        string $runId,
        string $source,
        string $relative,
        string $executionId,
        string $kind,
    ): void {
        $destination = RunPaths::runDir($runId).'/'.$relative;
        RunPaths::ensureDir(dirname($destination));
        if (realpath($source) !== realpath($destination) && ! copy($source, $destination)) {
            throw new RuntimeException(
                "rivals_native_attempt_copy_failed:{$executionId}:{$kind}",
            );
        }
    }
}

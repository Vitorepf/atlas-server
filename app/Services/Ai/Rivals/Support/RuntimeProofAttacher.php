<?php

namespace App\Services\Ai\Rivals\Support;

use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\VerbooEnvironment;

/** Binds external-suite receipts to the solver runtime that actually executed. */
final class RuntimeProofAttacher
{
    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    public function attach(array $receipt, array $binding, string $runId, string $resultPath): array
    {
        $model = (new ModelRegistry)->get((string) ($binding['model_id'] ?? '')) ?? [];
        if (($model['provider'] ?? null) !== 'hermes'
            || ! is_file(RunPaths::nativeManifestPath($runId))) {
            return $receipt;
        }
        $manifest = NativeExecutionManifest::load($runId);
        $entry = collect($manifest->entries())->first(
            fn (array $candidate): bool => $candidate['expected_result_path'] === $resultPath,
        );
        $nativeReceipt = collect(NativeExecutionReceipt::loadAll($runId))->first(
            fn (NativeExecutionReceipt $candidate): bool => $candidate->data['expected_result_path'] === $resultPath,
        );
        $metadata = (array) ($receipt['metadata'] ?? []);
        $runtime = (string) ($binding['runtime'] ?? '');
        if ($runtime === 'bare') {
            $tokensIn = (int) ($receipt['tokens_in'] ?? 0);
            $tokensOut = (int) ($receipt['tokens_out'] ?? 0);
            $providerBinding = $nativeReceipt instanceof NativeExecutionReceipt
                ? (array) ($nativeReceipt->data['provider_binding'] ?? [])
                : [];
            $real = is_array($entry)
                && $nativeReceipt instanceof NativeExecutionReceipt
                && $nativeReceipt->data['status'] === 'success'
                && ($nativeReceipt->data['runner']['mode'] ?? null) === 'execute'
                && ($providerBinding['provider'] ?? null) === 'verboo'
                && ($providerBinding['model_id'] ?? null) === ($binding['model_id'] ?? null)
                && ($providerBinding['atlas_cli_model'] ?? null) === ($binding['cli_model'] ?? null)
                && ($providerBinding['native_model'] ?? null) === ($binding['native_model'] ?? null)
                && ($providerBinding['base_url_sha256'] ?? null) === hash(
                    'sha256',
                    VerbooEnvironment::BASE_URL,
                )
                && ($tokensIn + $tokensOut) > 0;
            $metadata['direct_provider'] = [
                'schema_version' => 'atlas.rivals2.direct_provider_proof.v1',
                'real_provider' => $real,
                'provider' => 'verboo',
                'model' => (string) ($binding['cli_model'] ?? ''),
                'native_model' => (string) ($binding['native_model'] ?? ''),
                'execution_id' => $entry['execution_id'] ?? null,
                'command_hash' => $nativeReceipt?->data['command_hash'] ?? null,
                'result_sha256' => $nativeReceipt?->data['result_sha256'] ?? null,
                'usage' => [
                    'input_tokens' => $tokensIn,
                    'output_tokens' => $tokensOut,
                    'cost_usd' => (float) ($receipt['cost_usd'] ?? 0),
                    'present' => ($tokensIn + $tokensOut) > 0,
                ],
                'reason' => $real ? null : 'native_provider_execution_not_proven',
            ];
        } elseif ($runtime === 'atlas_dev') {
            $proof = is_array(data_get($receipt, 'metadata.runtime_bridge'))
                ? data_get($receipt, 'metadata.runtime_bridge')
                : (is_array($entry)
                    ? $this->atlasProof((string) data_get($entry, 'normalization.scratch_dir'))
                    : null);
            // `provider_calls` is an attempt counter, not proof of a response.
            // A real 19/07 receipt had calls=1 + provider_unavailable + no usage
            // and was incorrectly accepted. Since the Rivals bridge now captures
            // usage for successful provider responses, usage.present is the
            // observable response boundary. A task may still fail after that
            // response and remains a measured model/Atlas outcome.
            $valid = is_array($proof)
                && (new AtlasRuntimeProofValidator)->valid(
                    $proof,
                    (string) ($binding['cli_model'] ?? ''),
                    RunPaths::runDir($runId),
                );
            $proofReason = is_array($proof) && is_string($proof['failure_reason'] ?? null)
                && trim((string) $proof['failure_reason']) !== ''
                    ? trim((string) $proof['failure_reason'])
                    : (is_array($proof) && data_get($proof, 'usage.present') !== true
                        ? 'atlas_dev_runtime_proof_usage_missing'
                        : 'atlas_dev_runtime_proof_missing_or_invalid');
            $metadata['runtime_bridge'] = $valid
                ? $proof
                : [
                    'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v2',
                    'status' => 'failed',
                    'failure_reason' => $proofReason,
                    'real_provider' => false,
                    'model' => (string) ($binding['cli_model'] ?? ''),
                    'reason' => $proofReason,
                ];
            EventStream::append($runId, $valid ? 'bridge_proof_attached' : 'bridge_proof_missing', [
                'case_id' => $receipt['case_id'] ?? null,
                'arm_id' => $receipt['arm_id'] ?? null,
                'repetition' => $receipt['repetition'] ?? null,
                'runtime' => 'atlas_dev',
                'reason' => $valid ? null : $proofReason,
            ]);
            if (! $valid) {
                // Missing bridge is an environment/runtime proof failure — not model stupidity.
                $receipt['failure_class'] = FailureClass::ENVIRONMENT;
                $receipt['failure_reason'] = $proofReason;
                if (($receipt['status'] ?? null) === 'success') {
                    $receipt['status'] = 'error';
                }
            } elseif (($receipt['status'] ?? null) !== 'success'
                && data_get($proof, 'task_ok') === false) {
                $errorCodes = array_values(array_unique(array_filter(
                    array_map(
                        static fn (mixed $value): string => trim((string) $value),
                        (array) data_get($proof, 'provider_call.error_codes', []),
                    ),
                    static fn (string $value): bool => $value !== '',
                )));
                if ($errorCodes !== []) {
                    $receipt['failure_reason'] = (string) $manifest->data['suite_id']
                        .':atlas_bridge_error_codes='.implode('|', $errorCodes);
                }
            }
        }
        $receipt['metadata'] = $metadata;

        return $receipt;
    }

    /** @return array<string, mixed>|null */
    private function atlasProof(string $scratch): ?array
    {
        if ($scratch === '' || ! is_dir($scratch)) {
            return null;
        }
        $direct = $scratch.'/.rivals_atlas_dev_bridge.json';
        if (is_file($direct)) {
            $proof = json_decode((string) file_get_contents($direct), true);

            return is_array($proof) ? $proof : null;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scratch, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === '.rivals_atlas_dev_bridge.json') {
                $proof = json_decode((string) file_get_contents($file->getPathname()), true);

                return is_array($proof) ? $proof : null;
            }
        }

        return null;
    }
}

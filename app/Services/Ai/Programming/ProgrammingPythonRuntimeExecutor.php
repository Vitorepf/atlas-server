<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class ProgrammingPythonRuntimeExecutor
{
    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    public function execute(array $contract, string $decisionReceiptHash, bool $runtimeBoundaryGreen, bool $approved): array
    {
        $gate = $this->gate($contract, $decisionReceiptHash, $runtimeBoundaryGreen, $approved);
        if ($gate['status'] !== 'passed') {
            return [
                'schema_version' => 'atlas.programming.python_runtime.execution_receipt.v1',
                'status' => 'blocked',
                'gate' => $gate,
                'result' => null,
            ];
        }

        $manifest = (array) data_get($contract, 'manifest', []);
        $runtimeRoot = base_path((string) data_get($contract, 'runtime_root', 'runtimes/python/programming_intelligence'));
        $entrypoint = base_path((string) data_get($contract, 'entrypoint', 'runtimes/python/programming_intelligence/main.py'));
        $manifestPath = storage_path('app/atlas-programming-python-runtime-'.hash('sha256', json_encode($manifest) ?: '').'.json');

        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $process = new Process(['python3', $entrypoint, $manifestPath], base_path(), [
            'PYTHONPATH' => $runtimeRoot,
        ]);
        $process->setTimeout(30);
        $process->run();

        $payload = json_decode($process->getOutput(), true);
        $ok = $process->isSuccessful() && is_array($payload) && ($payload['ok'] ?? false) === true;

        try {
            File::delete($manifestPath);
        } catch (Throwable) {
            // Temporary manifest cleanup is best-effort; receipt still records execution status.
        }

        return [
            'schema_version' => 'atlas.programming.python_runtime.execution_receipt.v1',
            'status' => $ok ? 'passed' : 'failed',
            'gate' => $gate,
            'exit_code' => $process->getExitCode(),
            'result' => $ok ? $payload['result'] : null,
            'stderr_hash' => hash('sha256', $process->getErrorOutput()),
            'stdout_hash' => hash('sha256', $process->getOutput()),
            'receipt_hash' => hash('sha256', json_encode([
                'decision_receipt_hash' => $decisionReceiptHash,
                'manifest_hash' => $contract['manifest_hash'] ?? null,
                'exit_code' => $process->getExitCode(),
                'stdout_hash' => hash('sha256', $process->getOutput()),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function gate(array $contract, string $decisionReceiptHash, bool $runtimeBoundaryGreen, bool $approved): array
    {
        $reasons = [];
        if (! $approved) {
            $reasons[] = 'approval_required';
        }
        if (! $runtimeBoundaryGreen) {
            $reasons[] = 'runtime_boundary_not_green';
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $decisionReceiptHash)) {
            $reasons[] = 'decision_receipt_hash_required';
        }
        if (($contract['schema_version'] ?? null) !== 'atlas.programming.python_runtime.invocation_contract.v1') {
            $reasons[] = 'invalid_invocation_contract';
        }
        if (data_get($contract, 'manifest.runtime_policy.provider_calls_allowed') !== false
            || data_get($contract, 'manifest.runtime_policy.shell_calls_allowed') !== false
            || data_get($contract, 'manifest.runtime_policy.network_calls_allowed') !== false
            || data_get($contract, 'manifest.runtime_policy.memory_writes_allowed') !== false) {
            $reasons[] = 'unsafe_runtime_policy';
        }

        return [
            'schema_version' => 'atlas.programming.python_runtime.execution_gate.v1',
            'status' => $reasons === [] ? 'passed' : 'blocked',
            'reasons' => $reasons,
        ];
    }
}

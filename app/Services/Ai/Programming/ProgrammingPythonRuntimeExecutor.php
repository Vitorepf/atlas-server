<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Ai\Support\JsonFileStore;
use Symfony\Component\Process\Process;

class ProgrammingPythonRuntimeExecutor
{
    public function __construct(
        private readonly ProgrammingPythonRuntimePolicy $policy,
    ) {}

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
        $runtimeRootRelative = (string) data_get($contract, 'runtime_root', 'runtimes/python/programming_intelligence');
        $runtimeRoot = base_path($runtimeRootRelative);
        $entrypoint = base_path((string) data_get($contract, 'entrypoint', 'runtimes/python/programming_intelligence/main.py'));
        $manifestPath = JsonFileStore::writeTemporary(
            storage_path('app'),
            'atlas-programming-python-runtime',
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        try {
            $process = new Process([$this->pythonInterpreter($runtimeRoot), $entrypoint, $manifestPath], base_path(), [
                'PYTHONPATH' => $runtimeRoot,
            ]);
            $process->setTimeout(30);
            $process->run();
        } finally {
            JsonFileStore::deleteQuietly($manifestPath);
        }

        $payload = json_decode($process->getOutput(), true);
        $ok = $process->isSuccessful() && is_array($payload) && ($payload['ok'] ?? false) === true;

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
     * Resolve the Python interpreter for this runtime, mirroring
     * {@see SemanticRagRuntimeClient}: prefer the
     * runtime's own `.venv/bin/python` (where any third-party deps live) when it
     * exists, otherwise fall back to the system `python3`. The programming_intelligence
     * runtime is pure-stdlib AST analysis, so it has no venv today and resolves to
     * `python3` — but a runtime that adds deps (and ships a `.venv`) is honoured
     * without a code change, keeping the runtime-language boundary consistent.
     */
    private function pythonInterpreter(string $runtimeRoot): string
    {
        return ProviderRuntimeEnvironment::resolveNestedExecutable($runtimeRoot, '.venv/bin/python', 'python3');
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
        if (! $this->policy->isSafe((array) data_get($contract, 'manifest.runtime_policy', []))) {
            $reasons[] = 'unsafe_runtime_policy';
        }

        return [
            'schema_version' => 'atlas.programming.python_runtime.execution_gate.v1',
            'status' => $reasons === [] ? 'passed' : 'blocked',
            'reasons' => $reasons,
        ];
    }
}

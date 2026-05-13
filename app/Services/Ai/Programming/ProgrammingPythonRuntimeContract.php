<?php

namespace App\Services\Ai\Programming;

class ProgrammingPythonRuntimeContract
{
    /**
     * @param  array<int,string>  $files
     * @return array<string,mixed>
     */
    public function manifest(string $workspace, array $files, int $maxFiles = 40, int $maxBytesPerFile = 250000): array
    {
        $providerSafeFiles = collect($files)
            ->filter(fn (mixed $file): bool => is_string($file) && $file !== '' && ! str_starts_with($file, '/') && ! str_contains($file, '..'))
            ->values()
            ->take($maxFiles)
            ->all();

        $manifest = [
            'schema_version' => 'atlas.programming.python_runtime.request.v1',
            'workspace' => $workspace,
            'files' => $providerSafeFiles,
            'limits' => [
                'max_files' => $maxFiles,
                'max_bytes_per_file' => $maxBytesPerFile,
            ],
            'runtime_policy' => [
                'family' => 'python_ai_data',
                'capability' => 'programming_ast_embeddings',
                'provider_calls_allowed' => false,
                'shell_calls_allowed' => false,
                'network_calls_allowed' => false,
                'memory_writes_allowed' => false,
                'kernel_decides_runtime_executes' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.programming.python_runtime.invocation_contract.v1',
            'status' => $providerSafeFiles === [] ? 'empty' : 'ready',
            'runtime_root' => 'runtimes/python/programming_intelligence',
            'entrypoint' => 'runtimes/python/programming_intelligence/main.py',
            'manifest' => $manifest,
            'manifest_hash' => hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'execution' => [
                'auto_execute_from_planner' => false,
                'requires_decision_receipt' => true,
                'requires_runtime_boundary_green' => true,
                'allowed_command' => 'PYTHONPATH=runtimes/python/programming_intelligence python3 runtimes/python/programming_intelligence/main.py <manifest.json>',
            ],
        ];
    }
}

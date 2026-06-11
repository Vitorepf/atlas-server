<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\AiPathMatcher;

class ProgrammingPythonRuntimeContract
{
    public function __construct(
        private readonly ProgrammingPythonRuntimePolicy $policy,
    ) {}

    /**
     * @param  array<int,string>  $files
     * @return array<string,mixed>
     */
    public function manifest(string $workspace, array $files, int $maxFiles = 40, int $maxBytesPerFile = 250000): array
    {
        $providerSafeFiles = AiPathMatcher::providerSafeRelativePaths($files, $maxFiles);

        $manifest = [
            'schema_version' => 'atlas.programming.python_runtime.request.v1',
            'workspace' => $workspace,
            'files' => $providerSafeFiles,
            'limits' => [
                'max_files' => $maxFiles,
                'max_bytes_per_file' => $maxBytesPerFile,
            ],
            'runtime_policy' => $this->policy->manifestPolicy(),
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

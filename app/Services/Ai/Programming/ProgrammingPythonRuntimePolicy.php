<?php

namespace App\Services\Ai\Programming;

final class ProgrammingPythonRuntimePolicy
{
    /** @var array<int,string> */
    private const DISALLOWED_CAPABILITY_FLAGS = [
        'provider_calls_allowed',
        'shell_calls_allowed',
        'network_calls_allowed',
        'memory_writes_allowed',
    ];

    /**
     * @return array<string,mixed>
     */
    public function manifestPolicy(): array
    {
        return [
            'family' => 'python_ai_data',
            'capability' => 'programming_ast_embeddings',
            'provider_calls_allowed' => false,
            'shell_calls_allowed' => false,
            'network_calls_allowed' => false,
            'memory_writes_allowed' => false,
            'kernel_decides_runtime_executes' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $runtimePolicy
     */
    public function isSafe(array $runtimePolicy): bool
    {
        foreach (self::DISALLOWED_CAPABILITY_FLAGS as $flag) {
            if (($runtimePolicy[$flag] ?? null) !== false) {
                return false;
            }
        }

        return true;
    }
}

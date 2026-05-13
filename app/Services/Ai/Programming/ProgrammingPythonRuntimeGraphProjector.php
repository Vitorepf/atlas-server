<?php

namespace App\Services\Ai\Programming;

class ProgrammingPythonRuntimeGraphProjector
{
    /**
     * @param  array<string,mixed>  $executionReceipt
     * @return array<string,mixed>
     */
    public function project(array $executionReceipt): array
    {
        $files = collect((array) data_get($executionReceipt, 'result.files', []))
            ->filter(fn ($file): bool => is_array($file))
            ->values();

        $nodes = [];
        $edges = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $nodes[] = [
                'kind' => 'file',
                'path' => $path,
                'language' => $file['language'] ?? 'unknown',
                'reason' => 'python_runtime_file_analysis',
                'source' => 'programming_python_runtime',
                'hash' => $file['source_hash'] ?? null,
            ];

            foreach ((array) ($file['symbols'] ?? []) as $symbol) {
                if (! is_array($symbol) || ! is_string($symbol['name'] ?? null)) {
                    continue;
                }
                $symbolId = $path.'::'.$symbol['name'];
                $nodes[] = [
                    'kind' => 'symbol',
                    'path' => $path,
                    'symbol' => $symbol['name'],
                    'symbol_kind' => $symbol['kind'] ?? 'symbol',
                    'line' => $symbol['line'] ?? null,
                    'reason' => 'python_runtime_symbol_analysis',
                    'source' => 'programming_python_runtime',
                    'id' => $symbolId,
                ];
                $edges[] = [
                    'type' => 'declares_symbol',
                    'from' => $path,
                    'to' => $symbolId,
                    'source' => 'programming_python_runtime',
                ];
            }

            foreach ((array) ($file['imports'] ?? []) as $import) {
                if (! is_string($import) || $import === '') {
                    continue;
                }
                $edges[] = [
                    'type' => 'imports',
                    'from' => $path,
                    'to' => $import,
                    'source' => 'programming_python_runtime',
                ];
            }
        }

        return [
            'schema_version' => 'atlas.programming.python_runtime.graph_fragment.v1',
            'status' => $nodes === [] ? 'empty' : 'ready',
            'source_receipt_hash' => $executionReceipt['receipt_hash'] ?? null,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}

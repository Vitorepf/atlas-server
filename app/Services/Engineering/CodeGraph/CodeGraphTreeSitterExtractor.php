<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 A1 — wires the python tree-sitter op into the indexer.
 *
 * The PHP indexer extracts JS/TS symbols with two regexes (only `export function`
 * and `export interface|type|class|const` — it misses arrow components, methods,
 * non-exported decls) and extracts nothing for Go/Rust/Python/etc. This wrapper
 * hands a BATCH of files to the real tree-sitter AST runtime (one process for many
 * files) and returns precise multi-language symbols + import edges.
 *
 * Governed: batched through {@see CodeGraphRuntimeInvoker} (flag + Decision Receipt).
 * Read-model only. Returns [] when the runtime is blocked/unavailable so the caller
 * deterministically falls back to the existing regex path — never a hard dependency.
 */
class CodeGraphTreeSitterExtractor
{
    private const ACTOR = 'code-intelligence:index';

    private const OP = 'treesitter';

    public function __construct(private readonly CodeGraphRuntimeInvoker $invoker) {}

    /**
     * Extract symbols + import edges for a batch of files via tree-sitter.
     *
     * @param  array<int,array{path:string,language:string,content:string}>  $files
     * @return array<string,array{symbols:array<int,array<string,mixed>>,imports:array<int,string>}>
     *   keyed by file path; EMPTY when the runtime is blocked/unavailable (caller falls back).
     */
    public function extract(array $files): array
    {
        $files = array_values(array_filter(
            $files,
            static fn ($f): bool => is_array($f)
                && is_string($f['path'] ?? null) && $f['path'] !== ''
                && is_string($f['language'] ?? null) && $f['language'] !== ''
                && is_string($f['content'] ?? null),
        ));
        if ($files === []) {
            return [];
        }

        $input = ['files' => $files];
        $receipt = CodeGraphRuntimeInvoker::mintReceipt(self::OP, ['files' => count($files)], self::ACTOR);
        $result = $this->invoker->invoke(self::OP, $input, ['timeout_seconds' => 180], $receipt);

        if (($result['status'] ?? '') !== CodeGraphRuntimeInvoker::STATUS_SUCCEEDED) {
            return [];
        }

        $payload = $result['artifacts'][0]['result'] ?? [];
        if (! is_array($payload)) {
            return [];
        }

        $byPath = [];

        foreach ((array) ($payload['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $path = (string) ($node['path'] ?? '');
            $kind = (string) ($node['kind'] ?? '');
            if ($path === '' || $kind === '' || $kind === 'import') {
                continue;
            }
            $byPath[$path]['symbols'][] = $node;
        }

        foreach ((array) ($payload['edges'] ?? []) as $edge) {
            if (! is_array($edge) || ($edge['edge_type'] ?? '') !== 'imports') {
                continue;
            }
            $from = (string) ($edge['from_node_id'] ?? '');
            $to = (string) ($edge['to_node_id'] ?? '');
            if (! str_starts_with($from, 'file:')) {
                continue;
            }
            $path = substr($from, 5);
            $import = str_starts_with($to, 'import:') ? substr($to, 7) : $to;
            if ($import !== '') {
                $byPath[$path]['imports'][] = $import;
            }
        }

        foreach ($byPath as $path => $bucket) {
            $byPath[$path]['symbols'] ??= [];
            $byPath[$path]['imports'] ??= [];
        }

        return $byPath;
    }
}

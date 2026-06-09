<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Services\Ai\Compression\CompressionPipeline;

/**
 * AP-815 · E-1 (keystone) — compression-on-retrieval.
 *
 * The COMPOUND that makes context bizarrely cheap: the graph already reduces *what* to
 * read (E-3 minimal context pack), and this layer shrinks *what is left* by running the
 * assembled retrieval text through the governed AP-813 CompressionPipeline (CacheAligner
 * + ContentRouter + the 5 compressors + lossless-by-governance CCR). Net effect stacks
 * on top of the graph's own reduction.
 *
 * FAIL-OPEN by construction: when the compression layer is off, or anything errors, the
 * original text passes through untouched — retrieval is never blocked. [php] Kernel wiring;
 * the heavy compression work lives in the AP-813 layer it delegates to.
 */
class CodeGraphRetrievalCompressor
{
    public const SCHEMA = 'atlas.code_graph.retrieval_compressor.v1';

    private const CHARS_PER_TOKEN = 4;

    public function __construct(private readonly CompressionPipeline $pipeline) {}

    /**
     * Compress a single assembled retrieval text block + report the economy.
     *
     * @param  array<string,mixed>  $context
     * @return array{output:string,original_chars:int,compressed_chars:int,approx_tokens_saved:int,ratio:float,changed:bool,report:array<string,mixed>}
     */
    public function compress(string $text, array $context = []): array
    {
        $originalChars = strlen($text);

        $res = $this->pipeline->compressBlock($text, $context); // AP-813 layer is itself fail-open
        $output = is_string($res['output'] ?? null) ? $res['output'] : $text;

        // Guard: compression must never GROW the payload (fail-open to original if it did).
        if (strlen($output) > $originalChars) {
            $output = $text;
        }

        $compressedChars = strlen($output);
        $saved = max(0, $originalChars - $compressedChars);

        return [
            'output' => $output,
            'original_chars' => $originalChars,
            'compressed_chars' => $compressedChars,
            'approx_tokens_saved' => (int) ceil($saved / self::CHARS_PER_TOKEN),
            'ratio' => $originalChars > 0 ? round($saved / $originalChars, 4) : 0.0,
            'changed' => (bool) ($res['report']['changed'] ?? false),
            'report' => is_array($res['report'] ?? null) ? $res['report'] : [],
        ];
    }

    /**
     * Compress an E-3 context pack: serialize its included nodes, then compress.
     *
     * @param  array<string,mixed>  $pack     output of CodeGraphContextPackAssembler::assemble()
     * @param  array<string,mixed>  $context
     * @return array{output:string,original_chars:int,compressed_chars:int,approx_tokens_saved:int,ratio:float,changed:bool,nodes:int,report:array<string,mixed>}
     */
    public function compressPack(array $pack, array $context = []): array
    {
        $included = is_array($pack['included'] ?? null) ? $pack['included'] : [];
        $out = $this->compress($this->serialize($included), $context);
        $out['nodes'] = count($included);

        return $out;
    }

    /**
     * @param  array<int,mixed>  $nodes
     */
    private function serialize(array $nodes): string
    {
        $parts = [];
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $parts[] = $node;

                continue;
            }
            if (! is_array($node)) {
                continue;
            }
            $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            $content = '';
            foreach (['content', 'signature', 'snippet', 'body'] as $k) {
                if (isset($node[$k]) && is_string($node[$k])) {
                    $content = $node[$k];
                    break;
                }
            }
            $parts[] = $id !== '' ? "// {$id}\n{$content}" : $content;
        }

        return implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}

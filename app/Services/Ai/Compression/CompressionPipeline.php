<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression;

use App\Services\Ai\Compression\Support\TokenEstimator;
use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use Throwable;

/**
 * Orchestrates the compression layer (AP-813): cache-align the prefix, then detect
 * and compress content blocks, storing each original in the CCR store and leaving a
 * retrieval marker behind. Everything is FAIL-OPEN — any error returns the input
 * unchanged, so compression can never break a provider call.
 *
 * Two entry points:
 *   - transformPrompt(): used by {@see CompressionAiProvider} on the live provider
 *     path. Conservative — only touches the INNER content of fenced ``` blocks, so
 *     it can never mangle the surrounding prompt structure.
 *   - compressBlock(): compress one raw block (e.g. a large MCP tool output, or a
 *     measurement sample) where the caller already knows the whole string is data.
 */
final class CompressionPipeline
{
    /**
     * @param  array<string,mixed>  $config  config('atlas.compression_layer')
     */
    public function __construct(
        private readonly ContentRouter $router,
        private readonly AtlasCcrStore $ccr,
        private readonly VolatileTokenRelocator $relocator,
        private readonly array $config = [],
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Cache-align + compress fenced blocks in a full provider prompt. FAIL-OPEN.
     *
     * @param  array<string,mixed>  $context
     * @return array{prompt:string, report:array<string,mixed>}
     */
    public function transformPrompt(string $prompt, array $context = []): array
    {
        if (! $this->enabled() || $prompt === '') {
            return ['prompt' => $prompt, 'report' => ['changed' => false]];
        }

        try {
            return $this->doTransformPrompt($prompt, $context);
        } catch (Throwable $e) {
            return ['prompt' => $prompt, 'report' => ['changed' => false, 'error' => substr($e->getMessage(), 0, 160)]];
        }
    }

    /**
     * Compress a SINGLE raw block (MCP tool output / measurement). FAIL-OPEN.
     *
     * @param  array<string,mixed>  $context
     * @return array{output:string, report:array<string,mixed>}
     */
    public function compressBlock(string $block, array $context = []): array
    {
        $unchanged = ['output' => $block, 'report' => ['changed' => false]];

        if (! $this->enabled() || $block === '' || strlen($block) < $this->minBlockChars()) {
            return $unchanged;
        }

        try {
            $compressor = $this->router->route($block);
            if ($compressor === null) {
                return $unchanged;
            }
            $res = $compressor->compress($block, $this->compressorOptions());
            if (! $res->changed) {
                return $unchanged;
            }
            $hash = $this->maybeStore($block, $res->contentType, $context);

            return [
                'output' => $res->output.$this->marker($res, $hash),
                'report' => [
                    'changed' => true,
                    'content_type' => $res->contentType,
                    'chars_saved' => $res->saved(),
                    'approx_tokens_saved' => (int) ceil($res->saved() / TokenEstimator::CHARS_PER_TOKEN),
                    'ratio' => round($res->ratio(), 4),
                    'hash' => $hash,
                    'meta' => $res->meta,
                ],
            ];
        } catch (Throwable $e) {
            return ['output' => $block, 'report' => ['changed' => false, 'error' => substr($e->getMessage(), 0, 160)]];
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{prompt:string, report:array<string,mixed>}
     */
    private function doTransformPrompt(string $prompt, array $context): array
    {
        $relocated = 0;
        if ($this->cacheAlignerEnabled()) {
            $r = $this->relocator->relocate($prompt);
            $prompt = $r['prompt'];
            $relocated = $r['relocated'];
        }

        $minChars = $this->minBlockChars();
        $blocks = $this->extractFencedBlocks($prompt, $minChars);

        $blocksCompressed = 0;
        $charsSaved = 0;
        $spans = [];

        // Replace from LAST to FIRST so earlier byte offsets stay valid. Each block
        // is guarded independently: a single throwing/odd block is skipped (fail-open)
        // without losing the savings from the others.
        foreach (array_reverse($blocks) as $block) {
            try {
                $compressor = $this->router->route($block['content']);
                if ($compressor === null) {
                    continue;
                }
                $res = $compressor->compress($block['content'], $this->compressorOptions());
                if (! $res->changed) {
                    continue;
                }
                $hash = $this->maybeStore($block['content'], $res->contentType, $context);
                $replacement = $res->output.$this->marker($res, $hash);
                $prompt = substr($prompt, 0, $block['start']).$replacement.substr($prompt, $block['start'] + $block['length']);
                $blocksCompressed++;
                $charsSaved += $res->saved();
                $spans[] = [
                    'content_type' => $res->contentType,
                    'chars_saved' => $res->saved(),
                    'hash' => $hash,
                ];
            } catch (Throwable $e) {
                // Per-block fail-open: skip this block, keep the rest.
                continue;
            }
        }

        return [
            'prompt' => $prompt,
            'report' => [
                'changed' => $relocated > 0 || $blocksCompressed > 0,
                'relocated_tokens' => $relocated,
                'blocks_compressed' => $blocksCompressed,
                'chars_saved' => $charsSaved,
                'approx_tokens_saved' => (int) ceil($charsSaved / TokenEstimator::CHARS_PER_TOKEN),
                'spans' => $spans,
            ],
        ];
    }

    /**
     * @return list<array{start:int,length:int,content:string}>
     */
    private function extractFencedBlocks(string $prompt, int $minChars): array
    {
        $blocks = [];
        if (! preg_match_all('/```[^\n]*\n(.*?)```/s', $prompt, $matches, PREG_OFFSET_CAPTURE)) {
            return $blocks;
        }

        foreach ($matches[1] as $match) {
            $content = (string) $match[0];
            $start = (int) $match[1];
            if ($start >= 0 && strlen($content) >= $minChars) {
                $blocks[] = ['start' => $start, 'length' => strlen($content), 'content' => $content];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function maybeStore(string $original, string $contentType, array $context): ?string
    {
        if (! $this->ccrEnabled()) {
            return null;
        }

        $stored = $this->ccr->store($original, array_merge($context, ['content_type' => $contentType]));

        return is_string($stored['hash'] ?? null) ? $stored['hash'] : null;
    }

    private function marker(CompressionResult $res, ?string $hash): string
    {
        $itemsTotal = $res->meta['items_total'] ?? null;
        $itemsKept = $res->meta['items_kept'] ?? null;
        $desc = ($itemsTotal !== null && $itemsKept !== null)
            ? "{$itemsTotal} items compressed to {$itemsKept}"
            : "compressed {$res->originalChars}->{$res->compressedChars} chars";
        $retrieve = $hash !== null
            ? " · retrieve the full original via the atlas_ccr_retrieve tool with hash={$hash}"
            : '';

        return "\n[atlas:ccr {$desc}{$retrieve}]";
    }

    /**
     * @return array<string,mixed>
     */
    private function compressorOptions(): array
    {
        $sc = is_array($this->config['smart_crusher'] ?? null) ? $this->config['smart_crusher'] : [];

        return [
            'min_block_chars' => $this->minBlockChars(),
            'keep_head' => (int) ($sc['keep_head'] ?? 8),
            'keep_tail' => (int) ($sc['keep_tail'] ?? 4),
            'max_keep' => (int) ($sc['max_keep'] ?? 40),
        ];
    }

    private function minBlockChars(): int
    {
        return (int) ($this->config['min_block_chars'] ?? 800);
    }

    private function cacheAlignerEnabled(): bool
    {
        return (bool) data_get($this->config, 'cache_aligner.enabled', true);
    }

    private function ccrEnabled(): bool
    {
        return (bool) data_get($this->config, 'ccr.enabled', true);
    }
}

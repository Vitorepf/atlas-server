<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Compression\CompressionPipeline;
use App\Services\Ai\Compression\ContentRouter;
use App\Services\Ai\Compression\Compressors\DiffCompressor;
use App\Services\Ai\Compression\Compressors\LogCompressor;
use App\Services\Ai\Compression\Compressors\SearchCompressor;
use App\Services\Ai\Compression\Compressors\SmartCrusherJsonCompressor;
use App\Services\Ai\Compression\Compressors\TextCompressor;
use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use Illuminate\Support\ServiceProvider;

/**
 * AP-813 CCR store + CompressionPipeline DI (full-pass ASP peel).
 */
final class AtlasCompressionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AP-813 · CCR store (durable, ledger-backed). Singleton so the provider
        // pipeline AND the atlas_ccr_retrieve MCP tool share one configured instance.
        $this->app->singleton(AtlasCcrStore::class, function ($app) {
            $codec = (string) config('atlas.compression_layer.ccr.codec', 'gzip');
            $ledger = null;
            try {
                $ledger = $app->make(AtlasEvidenceLedger::class);
            } catch (\Throwable $e) {
                // CCR store degrades to no-ledger; it still persists the blob row.
            }

            return new AtlasCcrStore($ledger, $codec);
        });

        // AP-813 · Atlas Compression Layer pipeline (CacheAligner + CCR + content
        // compressors). Singleton, config-gated (default OFF). The ContentRouter is
        // populated resiliently: each leaf compressor is registered only if its
        // class exists AND its per-type flag is on — so the binding resolves cleanly
        // whether or not every compressor is present, and a broken compressor is
        // skipped rather than breaking the whole layer.
        $this->app->singleton(CompressionPipeline::class, function ($app) {
            $config = (array) config('atlas.compression_layer', []);
            $ccr = $app->make(AtlasCcrStore::class);

            $enabled = is_array($config['compressors'] ?? null) ? $config['compressors'] : [];
            $candidates = [
                'json' => SmartCrusherJsonCompressor::class,
                'log' => LogCompressor::class,
                'search' => SearchCompressor::class,
                'diff' => DiffCompressor::class,
                'text' => TextCompressor::class,
            ];
            $router = new ContentRouter;
            foreach ($candidates as $type => $class) {
                if (($enabled[$type] ?? true) === true && class_exists($class)) {
                    try {
                        $router->register($app->make($class));
                    } catch (\Throwable $e) {
                        // A broken/missing compressor must not break the pipeline.
                    }
                }
            }

            return new CompressionPipeline($router, $ccr, new VolatileTokenRelocator, $config);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRuntimeImplementationRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Self-Construction Runtime Implementation Roadmap
 * governance: the 9 sequential phases, the universal Phase Gate (tests + docs +
 * evidence + architecture validation + explicit residual risk before any phase
 * starts), the no-skip advancement rule and the read-only-before-autonomous-
 * patching boundary.
 *
 * @see docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
 */
final class AtlasRuntimeImplementationRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:runtime-implementation-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Self-Construction Runtime Implementation Roadmap governance: phase ladder, Phase Gate, no-skip advancement rule and the read-only-before-autonomous-patching boundary.';

    public function handle(AtlasRuntimeImplementationRoadmapService $service): int
    {
        try {
            $result = $service->snapshot();
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

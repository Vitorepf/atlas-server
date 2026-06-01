<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPersonalLongitudinalRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Personal Longitudinal Intelligence Roadmap.
 *
 * @see docs/engineering-knowledge-base/evolution/personal-longitudinal-roadmap.md
 */
final class AtlasPersonalLongitudinalRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:personal-longitudinal-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the personal longitudinal memory classes, their persistence defaults and the Curator authority gate.';

    public function handle(AtlasPersonalLongitudinalRoadmapService $service): int
    {
        try {
            $result = $service->roadmap();
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

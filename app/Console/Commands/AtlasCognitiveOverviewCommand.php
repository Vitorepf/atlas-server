<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveOverviewService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Plane overview decider CLI.
 *
 *   php artisan atlas:aaeos:cognitive-overview
 *     [--compression=3.0]   // tempo_natural / tempo_atlas speedup factor to classify
 *     [--json]
 *
 * Read-only, deterministic. Emits the north-question verdict for a sample
 * cognitive feature, the 4-pillars mastery gate, the temporal-compression band
 * for the given factor, and the canonical 5-movements sequence validation, so
 * the executive overview's invariants can never be silently skipped.
 *
 * @see docs/engineering-knowledge-base/cognitive/overview.md
 */
class AtlasCognitiveOverviewCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-overview
        {--compression= : temporal-compression factor (tempo_natural / tempo_atlas) to classify}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cognitive plane · north-question verdict, 4-pillars mastery gate, compression band, 5-movements order.';

    public function handle(AtlasCognitiveOverviewService $service): int
    {
        try {
            $factor = $this->option('compression') !== null
                ? (float) $this->option('compression')
                : AtlasCognitiveOverviewService::COMPRESSION_FLOOR;

            // A representative feature: multiplies cognitive output and stays in
            // the single Atlas channel -> should build.
            $north = $service->applyNorthQuestion([
                'multiplies_cognitive_output' => true,
                'single_channel' => true,
                'competes_with_learning' => false,
                'creates_escape' => false,
            ]);

            // A representative rubric covering only the theoretical pillar must
            // NOT mark the area mastered.
            $mastery = $service->evaluateMastery([
                AtlasCognitiveOverviewService::PILLAR_THEORETICAL => true,
                AtlasCognitiveOverviewService::PILLAR_PRACTICAL => false,
                AtlasCognitiveOverviewService::PILLAR_COGNITIVE => false,
                AtlasCognitiveOverviewService::PILLAR_TRANSFER => false,
            ]);

            $compression = $service->classifyCompression($factor);

            $movements = $service->validateMovementSequence(
                AtlasCognitiveOverviewService::MOVEMENTS,
            );

            $this->line((string) json_encode([
                'ok' => true,
                'schema' => AtlasCognitiveOverviewService::RECEIPT_SCHEMA,
                'north_question' => $north,
                'mastery' => $mastery,
                'compression' => $compression,
                'movements' => $movements,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cognitive_overview_evaluation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

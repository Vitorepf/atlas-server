<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveMultiplierEdgeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Cognitive Plane Multiplier Edge
 * governance read model: the 10 cardinal capabilities, the Dreyfus-first golden
 * rule, the Dreyfus pedagogy table, the Multi-Provider Discord Detector
 * restrictions, worked-example fading, routing scope and the predictive-failure
 * target guard.
 *
 * @see docs/engineering-knowledge-base/cognitive/multiplier-edge.md
 */
final class AtlasCognitiveMultiplierEdgeCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-multiplier-edge {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas cognitive Multiplier Edge governance: 10 cardinal capabilities, Dreyfus-first golden rule, Dreyfus pedagogy, Discord Detector restrictions, worked-example fading, routing scope and predictive-failure target guard.';

    public function handle(AtlasCognitiveMultiplierEdgeService $service): int
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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Cognitive Plane roadmap governance read
 * model: authority ordering, Dreyfus-first golden rule, Definition Of Done
 * gates, the validation-gated promotion rule and the AP status read model.
 *
 * @see docs/engineering-knowledge-base/cognitive/roadmap.md
 */
final class AtlasCognitiveRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas cognitive roadmap governance: authority chain, Dreyfus-first golden rule, Definition Of Done gates, validation-gated promotion and AP status.';

    public function handle(AtlasCognitiveRoadmapService $service): int
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

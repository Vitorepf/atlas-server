<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodeCategoryEvolutionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Code Category Evolution decider CLI.
 *
 *   php artisan atlas:aaeos:code-category-evolution [--json]
 *
 * Read-only and deterministic. Emits the canonical Category Stack and the
 * six-level Natural Progression ladder, then demonstrates the Boundary Rules
 * contract with a safe default: a Level 4 (Engineering Operations System /
 * Operating Room) claim whose framing wrongly asserts "self-evolving" — which
 * the doc forbids at Level 4 ("governed construction, not autonomous
 * self-evolution"). The decision therefore reports the boundary violation.
 *
 * @see docs/engineering-knowledge-base/atlas-code-category-evolution.md
 */
class AtlasCodeCategoryEvolutionCommand extends Command
{
    protected $signature = 'atlas:aaeos:code-category-evolution {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Code category-evolution classifier: Category Stack, six-level ladder and Boundary Rules.';

    public function handle(AtlasCodeCategoryEvolutionService $service): int
    {
        try {
            // Safe default: a Level 4 claim that over-states autonomy. Per the
            // doc Boundary Rules, Level 4 is governed construction, so a
            // "self-evolving" framing is a violation the decider must surface.
            $decision = $service->classifyClaim([
                'level' => 4,
                'reached_levels' => [1, 2, 3],
                'framing' => 'Atlas Code is now self-evolving at the Operating Room level.',
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'category' => AtlasCodeCategoryEvolutionService::CATEGORY_NAME,
                    'category_stack' => $service->categoryStack(),
                    'ladder' => $service->ladder(),
                    'decision' => $decision,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'code_category_evolution_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

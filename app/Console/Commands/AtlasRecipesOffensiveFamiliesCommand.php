<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesOffensiveFamiliesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Offensive Recipe Families CLI.
 *
 *   php artisan atlas:aaeos:recipes-offensive-families [--json]
 *
 * Read-only, deterministic. Emits the offensive-families taxonomy snapshot plus
 * a worked classification for a Red Team C2 candidate (`sliver`), proving the
 * family/tier/gates and the always-on dedicated-clause + VM sandbox + approval +
 * kill-switch obligations. Never executes a tool or touches state.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md
 */
class AtlasRecipesOffensiveFamiliesCommand extends Command
{
    protected $signature = 'atlas:aaeos:recipes-offensive-families {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · offensive recipe families taxonomy (family/tier/gates classifier, proposal-only).';

    public function handle(AtlasRecipesOffensiveFamiliesService $service): int
    {
        try {
            // Safe default: snapshot the taxonomy and classify a Red Team C2 tool,
            // the highest-gated case in the doc.
            $families = $service->families();
            $sample = $service->classify('sliver');

            $this->line((string) json_encode(
                ['ok' => true, 'families' => $families, 'sample_classification' => $sample],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'recipes_offensive_families_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

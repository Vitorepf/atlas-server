<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesExistingToolsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Existing Tools CLI.
 *
 *   php artisan atlas:aaeos:recipes-existing-tools [--json]
 *
 * Read-only, deterministic. Runs the reuse-before-propose gate: given a requested
 * cyber capability it answers `reuse` (an existing registry tool already produces
 * the evidence, named) or `propose_new` (tool, mode or evidence shape missing). The
 * safe default models a secret-scanning request, which the doc says must reuse
 * gitleaks rather than spawn a new recipe.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md
 */
class AtlasRecipesExistingToolsCommand extends Command
{
    protected $signature = 'atlas:aaeos:recipes-existing-tools {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · existing-tools reuse gate (reuse a registered tool vs propose a new recipe).';

    public function handle(AtlasRecipesExistingToolsService $service): int
    {
        try {
            // Safe default: a secret-scanning capability request. Per the doc Rule,
            // this must reuse the already-registered gitleaks tool.
            $request = [
                'capability' => 'secret_scan',
                'requested_mode' => null,
                'evidence_shape_missing' => false,
            ];

            $result = $service->evaluate($request);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cyber_existing_tools_reuse_gate_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

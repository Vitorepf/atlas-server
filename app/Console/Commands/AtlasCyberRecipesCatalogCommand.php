<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCyberRecipesCatalogService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Recipes Catalog CLI.
 *
 *   php artisan atlas:aaeos:cyber-recipes-catalog [--json]
 *
 * Read-only, deterministic. Evaluates a proposed cyber recipe declaration against
 * the catalog's Required Recipe Contract + Hard Safety Rules and emits an
 * admit|reject receipt. The safe default models a fully-compliant offensive
 * (active exploitation) recipe carrying its required approval.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
 */
class AtlasCyberRecipesCatalogCommand extends Command
{
    protected $signature = 'atlas:aaeos:cyber-recipes-catalog {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · recipes catalog admission decider (Required Recipe Contract + Hard Safety Rules, admit|reject).';

    public function handle(AtlasCyberRecipesCatalogService $service): int
    {
        try {
            // Safe default: a fully-compliant active-exploitation recipe with its
            // extra approval recorded and offensive dry-run default honored.
            $recipe = [
                'tool_slug' => 'atlas-active-exploit-runner',
                'recipe_name' => 'web-exploit-validate',
                'category' => 'active_exploit',
                'argv' => ['--target', '{{scope.in}}', '--exploit', '{{module}}'],
                'dry_run_default' => true,
                'creates_evidence' => true,
                'blocking_capable' => false,
                'execution_tier' => 'T2',
                'sandbox' => 'dedicated_vm',
                'privacy_level' => 'secret',
                'task_type' => 'offensive_validation',
                'authority_group' => 'cyber_offense',
                'offensive' => true,
                'approval_recorded' => true,
            ];

            $result = $service->evaluateRecipe($recipe);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['verdict'] === AtlasCyberRecipesCatalogService::VERDICT_ADMIT
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cyber_recipes_catalog_admission_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

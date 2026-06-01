<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesPromotionRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Recipes Promotion Runbook CLI.
 *
 *   php artisan atlas:aaeos:recipes-promotion-runbook [--json]
 *
 * Read-only, deterministic. Evaluates a cyber-recipe promotion attempt against the
 * runbook's eight Promotion Steps, Required Gates and seven-item Definition Of Done
 * and emits a promote|hold receipt. The safe default models a fully-green offensive
 * (active exploitation) promotion: every step done, every applicable gate green,
 * every Definition-of-Done item satisfied, no provider/raw-MCP bypass.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md
 */
class AtlasRecipesPromotionRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:recipes-promotion-runbook {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · recipes promotion runbook gate (Promotion Steps + Required Gates + Definition Of Done, promote|hold).';

    public function handle(AtlasRecipesPromotionRunbookService $service): int
    {
        try {
            // Safe default: a fully-green active-exploitation promotion. Every
            // Promotion Step completed, every applicable Required Gate green
            // (including conditional sandbox + approval), and every Definition Of
            // Done item satisfied — including no provider/raw-MCP bypass.
            $attempt = [
                'category' => 'active_exploit',
                'offensive' => true,
                'steps' => array_fill_keys(AtlasRecipesPromotionRunbookService::PROMOTION_STEPS, true),
                'gates' => [
                    'scope_proof' => true,
                    'refusal_matrix' => true,
                    'decision_receipt' => true,
                    'evidence' => true,
                    'sandbox' => true,
                    'approval' => true,
                ],
                'definition_of_done' => array_fill_keys(AtlasRecipesPromotionRunbookService::DEFINITION_OF_DONE, true),
            ];

            $result = $service->evaluatePromotion($attempt);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['verdict'] === AtlasRecipesPromotionRunbookService::VERDICT_PROMOTE
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'recipes_promotion_runbook_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

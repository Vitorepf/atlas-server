<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofDesignSystemMigrationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Design-system-migration product-proof gate evaluator CLI.
 *
 *   php artisan atlas:aaeos:programming-frontend-product-proof-design-system-migration
 *     [--viewports=desktop,tablet,mobile]
 *     [--evidence=design_system_drift_check,anti_slop,visual_smoke,completion_hash]
 *     [--flow-steps=ui_inventory,token_migration,before_after_comparison]
 *     [--claims-complete]
 *     [--scope-explicit]
 *     [--before-after-diff]
 *     [--token-migration]
 *     [--visual-regression]
 *     [--json]
 *
 * Read-only, deterministic. Emits the ready/blocked/claim_violation verdict +
 * receipt for one declared design-system-migration demo. With no options it
 * evaluates the canonical fully-green sample (gates green, no completion claim).
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-design_system_migration.md
 */
class AtlasProgrammingFrontendProductProofDesignSystemMigrationCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-product-proof-design-system-migration
        {--viewports= : comma-separated rendered viewports (default desktop,tablet,mobile)}
        {--evidence= : comma-separated captured evidence ids (default all four gates)}
        {--flow-steps= : comma-separated Fluxo steps performed (default inventory,tokens,before/after)}
        {--claims-complete : the demo declares the migration complete}
        {--scope-explicit : the migration scope is explicitly declared}
        {--before-after-diff : a before/after diff was captured}
        {--token-migration : legacy values were migrated onto design tokens}
        {--visual-regression : a visual regression check ran}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas frontend · design-system-migration product-proof gate (ready|blocked|claim_violation).';

    public function handle(AtlasProgrammingFrontendProductProofDesignSystemMigrationService $service): int
    {
        try {
            $demo = $service->readySample();

            foreach (['viewports' => 'viewports', 'evidence' => 'evidence', 'flow-steps' => 'flow_steps'] as $opt => $key) {
                $raw = $this->option($opt);
                if (is_string($raw) && trim($raw) !== '') {
                    $demo[$key] = array_values(array_filter(
                        array_map('trim', explode(',', $raw)),
                        static fn ($v) => $v !== '',
                    ));
                }
            }

            $demo['claims_migration_complete'] = (bool) $this->option('claims-complete');
            $demo['migration_scope_explicit'] = (bool) $this->option('scope-explicit');
            $demo['before_after_diff'] = (bool) $this->option('before-after-diff');
            $demo['token_migration'] = (bool) $this->option('token-migration');
            $demo['visual_regression'] = (bool) $this->option('visual-regression');

            $verdict = $service->evaluate($demo);

            $this->line((string) json_encode(
                ['ok' => true, 'verdict' => $verdict],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'design_system_migration_product_proof_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

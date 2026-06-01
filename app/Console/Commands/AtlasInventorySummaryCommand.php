<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasInventorySummaryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Legacy Cleanup Inventory Summary decider CLI.
 *
 *   php artisan atlas:aaeos:inventory-summary [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the
 * inventory's three contracts: the "Decision Criteria" table classifying an
 * index-listed item as keep_canonical, a "Current Families" routing, and the
 * "Risk Rules" rejecting a class change made without traceability for audit.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md
 */
class AtlasInventorySummaryCommand extends Command
{
    protected $signature = 'atlas:aaeos:inventory-summary {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · legacy cleanup inventory summary (classify inventory class + family handling + risk rules).';

    public function handle(AtlasInventorySummaryService $service): int
    {
        try {
            // Decision Criteria: an item listed by README/START_HERE/canonical
            // index is kept canonical.
            $classify = $service->classify([
                'listed_in_index' => true,
            ]);

            // Current Families: a deprecated KB stub is kept with redirect and
            // never expanded.
            $family = $service->handleFamily('deprecated_kb_stubs');

            // Risk Rules: changing an item's class without preserving
            // traceability for future audits is rejected.
            $riskRules = $service->checkRiskRules([
                'changes_class' => true,
                'preserves_traceability' => false,
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'classify' => $classify,
                    'family' => $family,
                    'risk_rules' => $riskRules,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'legacy_cleanup_inventory_summary_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

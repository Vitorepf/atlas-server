<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableCodeInventoryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the "Impeccable Code Inventory For Atlas
 * Frontend" doc. With no args it renders the verified per-area file inventory
 * and runs the doc's authorial-vs-total reconciliation (1691 total -> 670
 * authorial after excluding generated provider bundles). Proves the doc's
 * load-bearing rules are live: capacity is measured on authorial source only,
 * never on generated bundles.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
 */
class AtlasProgrammingFrontendImpeccableCodeInventoryCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-impeccable-code-inventory {--json : Print machine-readable JSON}';

    protected $description = 'Render the audited Impeccable file inventory and reconcile authorial vs total files (never a runtime claim).';

    public function handle(AtlasProgrammingFrontendImpeccableCodeInventoryService $service): int
    {
        try {
            $inventory = $service->inventory();
            $reconcile = $service->reconcileAuthorialFiles();
            $primary = $service->evaluatePrimaryCoverage();

            $payload = [
                'ok' => true,
                'schema_version' => $inventory['schema_version'],
                'mode' => $inventory['mode'],
                'inventory' => $inventory,
                'reconciliation' => $reconcile,
                'primary_coverage' => $primary,
                'flow' => $service->flow(),
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasProgrammingFrontendImpeccableCodeInventoryService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('external_subject', (string) $inventory['external_subject']);
        $this->components->twoColumnDetail('pinned_commit', (string) $inventory['pinned_commit']);
        $this->components->twoColumnDetail('total_files', (string) $inventory['total_files']);
        $this->components->twoColumnDetail('authorial_files', (string) $inventory['authorial_files']);
        $this->components->twoColumnDetail('area_count', (string) $inventory['area_count']);
        $this->components->twoColumnDetail('authorial_split_matches', $reconcile['matches_documented_split'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}

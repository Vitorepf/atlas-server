<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasVaultCartographySchemaRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Vault Cartography Schema Runbook decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-vault-cartography-schema-runbook [--json]
 *
 * Exercises the four runbook decision surfaces over safe reference inputs:
 * reader-model binding, missing-source resolution, the migration-phase gate and
 * the live-documentation flow. Read-only and deterministic; it never walks the
 * filesystem, opens a source, writes a doc or emits evidence.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md
 */
class AtlasVaultCartographySchemaRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-vault-cartography-schema-runbook {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Vault Cartography Schema Runbook · binds a source-aware reader, resolves a missing source, gates the hardcoded-data deprecation phase and orders the live-doc flow against the documented rules.';

    public function handle(AtlasVaultCartographySchemaRunbookService $service): int
    {
        try {
            // Safe reference sample: a vault piece binds the vault reader (deduped
            // against repo); a missing source renders missing_source with open
            // disabled; deprecating hardcoded data is blocked when Phase 2/3 are
            // not done; the live-doc flow advances from its first step.
            $vaultBinding = $service->resolveReaderBinding([
                'source' => AtlasVaultCartographySchemaRunbookService::SOURCE_VAULT,
            ]);

            $missing = $service->resolveSource([
                'exists' => false,
                'expected_path' => 'docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md',
                'cached_content' => '# last known body',
            ]);

            $healthy = $service->resolveSource([
                'exists' => true,
                'stale' => false,
                'expected_path' => 'docs/engineering-knowledge-base/vault/runbook.md',
            ]);

            // Only Phase 2 done (not Phase 3) => deprecation must stay blocked.
            $deprecateBlocked = $service->canDeprecateHardcoded([0, 1, 2]);
            $deprecateAllowed = $service->canDeprecateHardcoded([0, 1, 2, 3]);

            $nextPhase = $service->nextPhase([0, 1]);
            $flow = $service->liveDocFlowStep(1);

            $payload = [
                'ok' => true,
                'schema' => AtlasVaultCartographySchemaRunbookService::SCHEMA,
                'reader_binding_vault' => $vaultBinding,
                'missing_source' => $missing,
                'healthy_source' => $healthy,
                'deprecate_hardcoded_blocked' => $deprecateBlocked,
                'deprecate_hardcoded_allowed' => $deprecateAllowed,
                'next_phase' => $nextPhase,
                'live_doc_flow' => $flow,
                'migration_phases' => AtlasVaultCartographySchemaRunbookService::MIGRATION_PHASES,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is healthy when the documented invariants hold:
            // a missing source is never openable, and deprecation stays blocked
            // until the graph API is the source of data.
            $invariantsHold = $missing['open_source_enabled'] === false
                && $missing['render_missing_source'] === true
                && $deprecateBlocked['allowed'] === false
                && $deprecateAllowed['allowed'] === true;

            return $invariantsHold ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_vault_cartography_schema_runbook_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLegacyDocumentationCleanupReportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Legacy Documentation Cleanup Report decider CLI.
 *
 *   php artisan atlas:aaeos:legacy-documentation-cleanup-report [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the report's
 * three contracts: vault material is routed to the Human Knowledge Surface, a
 * legacy doc never overrides a canonical authority in a conflict, and a wave
 * that deletes in the same pass it first archives violates a Non-Negotiable.
 *
 * @see docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
 */
class AtlasLegacyDocumentationCleanupReportCommand extends Command
{
    protected $signature = 'atlas:aaeos:legacy-documentation-cleanup-report {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · legacy documentation cleanup report (classify cleanup class + resolve authority + non-negotiables).';

    public function handle(AtlasLegacyDocumentationCleanupReportService $service): int
    {
        try {
            // Vault/personal content is a Human Knowledge Surface, not raw source.
            $classify = $service->classify([
                'kind' => 'source_material',
                'from_vault' => true,
            ]);

            // In a conflict, a legacy cleanup doc never overrides the canonical
            // architecture index.
            $authority = $service->resolveAuthority('legacy_cleanup_report', 'canonical_architecture_index');

            // A wave that deletes in the same pass it first archives breaks a
            // Non-Negotiable.
            $nonNegotiables = $service->checkNonNegotiables([
                'archives_now' => true,
                'deletes_now' => true,
                'first_archive_wave' => true,
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'classify' => $classify,
                    'authority' => $authority,
                    'non_negotiables' => $nonNegotiables,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'legacy_documentation_cleanup_report_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

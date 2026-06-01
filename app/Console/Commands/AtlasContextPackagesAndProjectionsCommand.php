<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasContextPackagesAndProjectionsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Context Packages And Projections governance: the
 * ten-section package contract, the worked package-selection example and the
 * five Projection Law rules.
 *
 *   php artisan atlas:aaeos:context-packages-and-projections [--json]
 *
 * Read-only and deterministic. With no flags it emits the governance snapshot and
 * a live evaluation of the documented React + Laravel UI save selection plus a
 * sample projection-admissibility verdict. It NEVER touches the database.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md
 */
final class AtlasContextPackagesAndProjectionsCommand extends Command
{
    protected $signature = 'atlas:aaeos:context-packages-and-projections {--json : Machine-readable JSON output}';

    protected $description = 'Atlas context packages and projections · ten-section package contract, package selection and the five Projection Law rules.';

    public function handle(AtlasContextPackagesAndProjectionsService $service): int
    {
        try {
            $selection = $service->selectPackages([
                'stack' => ['react', 'typescript', 'laravel'],
                'changed_file_types' => ['tsx', 'php', 'save'],
                'risk_level' => 'medium',
                'has_receipt' => true,
            ]);

            $projection = $service->evaluateProjection([
                'declared_source' => 'docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md',
                'declared_timestamp' => '2026-06-01T00:00:00Z',
                'drift_checked' => true,
                'drift_detected' => false,
                'verified_against_canonical' => true,
                'risk_level' => 'medium',
            ]);

            $result = [
                'ok' => true,
                'snapshot' => $service->snapshot(),
                'selection_example' => $selection,
                'projection_example' => $projection,
            ];

            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'context_packages_and_projections_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

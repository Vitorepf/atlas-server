<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDomainsProgrammingSurfacesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Programming Surfaces resolver CLI.
 *
 *   php artisan atlas:aaeos:atlas-domains-programming-surfaces [--json]
 *
 * Read-only and deterministic. With the safe default it runs the doc's load-
 * bearing example: an `Atlas Code` invocation WITHOUT an obra_id must be ruled
 * inadmissible (Atlas Code binds programming.forge only and requires obra_id),
 * while the same surface with a valid obra_id and the forge flow is admissible.
 *
 * @see docs/engineering-knowledge-base/domains/programming-surfaces.md
 */
class AtlasDomainsProgrammingSurfacesCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-domains-programming-surfaces {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI · Programming surface→flow contract resolver (thin adapters into programming.* flows).';

    public function handle(AtlasDomainsProgrammingSurfacesService $service): int
    {
        try {
            // Doc example: Atlas Code missing obra_id => inadmissible.
            $atlasCodeNoObra = $service->admit([
                'surface' => 'Atlas Code',
            ]);

            // Same surface, valid obra_id on the forge flow => admissible.
            $atlasCodeOk = $service->admit([
                'surface' => 'Atlas Code',
                'flow' => 'programming.forge',
                'obra_id' => 'obra_demo_001',
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'atlas_code_missing_obra' => $atlasCodeNoObra,
                'atlas_code_with_obra' => $atlasCodeOk,
                'table' => $service->table(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'programming_surfaces_resolve_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

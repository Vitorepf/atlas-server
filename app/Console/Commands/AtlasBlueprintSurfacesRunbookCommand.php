<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasBlueprintSurfacesRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Engineering Blueprint Surfaces Runbook — surface parity audit CLI.
 *
 *   php artisan atlas:aaeos:blueprint-surfaces-runbook [--json]
 *
 * Read-only, deterministic. Audits the documented App/CLI/API surface
 * inventories of the Engineering Blueprint System against the runbook's Parity
 * Rule: an operation present on one surface must exist on the others or carry an
 * explicit documented omission reason. With no flags it audits the documented
 * surfaces exactly as written (no omissions) => ok=true. It never calls a
 * surface, mutates routes/commands/screens, or promotes status.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
 */
class AtlasBlueprintSurfacesRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:blueprint-surfaces-runbook
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Blueprint surfaces · audit App/CLI/API parity against the runbook Parity Rule.';

    public function handle(AtlasBlueprintSurfacesRunbookService $service): int
    {
        try {
            $result = $service->auditDocumented();

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->getMessage()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::FAILURE;
        }
    }
}

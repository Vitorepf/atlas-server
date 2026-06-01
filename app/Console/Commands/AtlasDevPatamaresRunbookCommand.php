<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevPatamaresRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Patamares Runbook — A0..A7 promotion-gate audit CLI.
 *
 *   php artisan atlas:aaeos:dev-patamares-runbook [--json]
 *
 * Read-only, deterministic. Emits the documented A0..A7 ladder (slices, DTO,
 * gate per patamar) and, with safe defaults, evaluates a sample A1 -> A2
 * promotion against the runbook gate (all A1 slices green + 50-run threshold).
 * It never promotes runtime state, never skips a patamar and never relaxes a
 * gate.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-patamares-runbook.md
 */
class AtlasDevPatamaresRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:dev-patamares-runbook
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Dev patamares · audit A0..A7 slices, DTOs and promotion gates against the runbook.';

    public function handle(AtlasDevPatamaresRunbookService $service): int
    {
        try {
            // Safe default: demonstrate the gate on a sample A1 -> A2 promotion
            // where every A1 slice is green and the 50-run threshold is reached.
            $result = $service->audit([
                'promotion' => [
                    'from' => 'A1',
                    'to' => 'A2',
                    'slice_status' => [
                        'discovery' => true,
                        'prompt projection' => true,
                        'telemetry' => true,
                        'persistence' => true,
                    ],
                    'green_count' => 50,
                ],
            ]);

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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevPatamaresService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev — Patamares (A0..A7) maturity-ladder CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-patamares [--json]
 *
 * Runs the whole-ladder audit over a reference bundle and emits the pass|fail
 * evidence document: the ordered A0..A7 ladder with capability phrases, statuses
 * and prerequisites, the current "em construcao" patamar, a work-scope anti-pattern
 * check and a "Mudanca De Patamar" promotion check. Read-only and deterministic.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-patamares.md
 */
class AtlasDevPatamaresCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-patamares {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Dev · audits a bundle against the A0-A7 patamares ladder, the current build target, the future-patamar anti-pattern guard and the promotion process gates.';

    public function handle(AtlasDevPatamaresService $service): int
    {
        try {
            // Safe default: a conformant reference bundle that demonstrates a green
            // audit. Work scope targets the current patamar; the promotion from the
            // current patamar to its successor passes all five process gates.
            $bundle = [
                'work_scope' => [
                    'requested' => 'A1',
                ],
                'promotion' => [
                    'from' => 'A1',
                    'to' => 'A2',
                    'gates' => [
                        'ap_approved' => true,
                        'capability_phrase_defined' => true,
                        'components_implemented_and_tested' => true,
                        'previous_cert_green' => true,
                        'status_bump' => true,
                    ],
                ],
            ];

            $result = $service->audit($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasDevPatamaresService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_patamares_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

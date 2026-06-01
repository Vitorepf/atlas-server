<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasQualitativeLevelsImplementationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runtime surface for the Atlas AI Qualitative Levels Implementation Queue doc.
 *
 *   php artisan atlas:aaeos:qualitative-levels-implementation [--json]
 *
 * Read-only and deterministic. By default it prints the implementation queue
 * (QL-0 .. QL-7 with normalized states) and demonstrates the "## Rule" gate by
 * evaluating a pending P4 claim with no gates — which the doc requires to be
 * blocked (pending scheduled review is discipline, not proof).
 *
 * @see docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md
 */
final class AtlasQualitativeLevelsImplementationCommand extends Command
{
    protected $signature = 'atlas:aaeos:qualitative-levels-implementation {--json : Print machine-readable JSON}';

    protected $description = 'Inspect the Atlas qualitative levels implementation queue and enforce the P4+ promotion gate.';

    public function handle(AtlasQualitativeLevelsImplementationService $service): int
    {
        try {
            $queue = $service->queue();

            // Safe default demonstration of the Rule: a P4 claim whose readiness
            // is still pending (scheduled review attached) and which carries no
            // gates MUST be blocked.
            $sampleClaim = $service->evaluateP4Claim([
                'level' => 'P4',
                'readiness_status' => 'pending',
                'scheduled_review' => true,
                'gates' => [],
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'queue' => $queue,
                    'sample_p4_claim' => $sampleClaim,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'qualitative_levels_implementation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiMultiDomainImplementationSequenceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Multi-Domain Implementation Sequence decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-multi-domain-implementation-sequence [--json]
 *
 * Read-only and deterministic. With the safe default it runs the doc's own
 * "build Cyber now" example: asking to start meta 11 (Cyber Security) with an
 * empty ready set must be blocked, listing the missing prerequisites (1,2,3,4,
 * 5,6,8) that have to be opened first.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
 */
class AtlasAiMultiDomainImplementationSequenceCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-multi-domain-implementation-sequence {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI · multi-domain implementation-sequence decider (start gating + parallelism) for the 14 canonical metas.';

    public function handle(AtlasAiMultiDomainImplementationSequenceService $service): int
    {
        try {
            // Safe default: the doc's "construir Cyber agora" example. Cyber (11)
            // with nothing ready must be blocked on its prerequisites.
            $cyberNow = $service->canStart([
                'meta' => 11,
                'ready_metas' => [],
            ]);

            // The "Control Plane UX before runtime" example: meta 14 refused.
            $uxBeforeRuntime = $service->canStart([
                'meta' => 14,
                'ready_metas' => [],
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'cyber_now' => $cyberNow,
                'control_plane_ux_before_runtime' => $uxBeforeRuntime,
                'meta_order' => $service->metaOrder(),
                'cited_contracts' => $service->citedContracts(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'multi_domain_implementation_sequence_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSddImplementationRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas SDD Implementation Roadmap governance:
 * the four sequential phases (Foundation -> Auto-Spec -> Controlled Execution
 * -> State Of Art), the no-skip advancement rule, the deliverable-prerequisite
 * gate, the "First Safe Block" read-only preview pipeline ending in "no code",
 * and the no-runtime-claim-without-evidence-and-green-gates guard.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md
 */
final class AtlasSddImplementationRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:sdd-implementation-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas SDD Implementation Roadmap governance: four phases, no-skip advancement, deliverable prerequisites, the First Safe Block read-only preview and the runtime-claim guard.';

    public function handle(AtlasSddImplementationRoadmapService $service): int
    {
        try {
            $result = $service->snapshot();
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

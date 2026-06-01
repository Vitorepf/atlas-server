<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsCompletionRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Self-Construction OS Completion Roadmap
 * governance: the 6 sequential phases, the no-skip rule, mandatory regression
 * return, phase-exit hard prohibitions and the honest maturity estimate.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
 */
final class AtlasSelfConstructionOsCompletionRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-os-completion-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Self-Construction OS Completion Roadmap governance: phase ladder, no-skip advancement rule, mandatory regression return, phase-exit prohibitions and current maturity.';

    public function handle(AtlasSelfConstructionOsCompletionRoadmapService $service): int
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

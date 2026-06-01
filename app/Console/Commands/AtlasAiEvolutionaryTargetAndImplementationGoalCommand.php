<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionaryTargetAndImplementationGoalService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Evolutionary Target And Implementation
 * Goal briefing runtime: the claim guard (six forbidden claims), the promotion
 * dependency chain (doc -> runtime -> caller -> test -> standard-flow ->
 * control-plane -> evidence), the artifact contract taxonomy, the ordered
 * operational action sequence and the immediate-meta Definition of Done.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolutionary-target-and-implementation-goal.md
 */
final class AtlasAiEvolutionaryTargetAndImplementationGoalCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-evolutionary-target-and-implementation-goal {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas AI Evolutionary Target briefing: claim guard, promotion chain, artifact taxonomy, action sequence and meta Definition of Done.';

    public function handle(AtlasAiEvolutionaryTargetAndImplementationGoalService $service): int
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

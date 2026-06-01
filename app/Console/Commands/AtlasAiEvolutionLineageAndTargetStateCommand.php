<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionLineageAndTargetStateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Evolution Lineage And Target State read
 * model: the macro compass. Shows Atlas's identity (AI Operating System, not
 * AGI/ASI), the eleven-level lineage with per-level operational status, the
 * conservative current state (Nivel 4/5 consolidating, Nivel 6 emerging), the
 * "Unidade de evolucao" Yes/No table, the claim policy, the minimum promotion
 * evidence and the horizon targets.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
 */
final class AtlasAiEvolutionLineageAndTargetStateCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-evolution-lineage-and-target-state {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas AI evolution lineage and target state: Nivel 0-10 lineage, current state, evolution-unit table, claim policy and horizon targets.';

    public function handle(AtlasAiEvolutionLineageAndTargetStateService $service): int
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

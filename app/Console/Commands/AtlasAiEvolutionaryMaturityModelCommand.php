<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionaryMaturityModelService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Evolutionary Maturity Model read model:
 * the canonical 11-level lineage (Nivel 0..10), the conservative current
 * classification (Nivel 4.5-5, emerging Nivel 6, not AGI/ASI) and the eight
 * promotion proofs every level promotion requires.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
 */
final class AtlasAiEvolutionaryMaturityModelCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-evolutionary-maturity-model {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas AI Evolutionary Maturity Model: canonical Nivel 0-10 lineage, current classification and the promotion proof gate.';

    public function handle(AtlasAiEvolutionaryMaturityModelService $service): int
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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopCounterfactualEditEvaluator;
use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopSimulableTwinOrchestrator;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCounterfactualEditEvaluator::chooseBest()} at the operator surface: chooses
 * the best counterfactual edit among candidates via the {@see AtlasLoopSimulableTwinOrchestrator} and a
 * DETERMINISTIC applier closure — each candidate is "applied" by returning its own declared simulated
 * outcome — and emits the ranking + winner as deterministic facts. Read-only: the twin applies in a mirror
 * and always reverts; nothing real is touched.
 *
 * --candidates accepts inline JSON or a path to a JSON file (a list; each candidate may carry a
 * `simulated_outcome` payload, a `mirror_execution_plan`, and a `breaks` count).
 */
final class AtlasLoopCounterfactualChooseCommand extends Command
{
    protected $signature = 'atlas:loop:counterfactual-choose {--candidates=} {--json}';

    protected $description = 'Read-only: choose the best counterfactual edit among candidates (simulable twin, reverted).';

    public function handle(AtlasLoopSimulableTwinOrchestrator $twin, AtlasLoopCounterfactualEditEvaluator $evaluator): int
    {
        $candidatesOption = $this->option('candidates');
        if ($candidatesOption === null || trim((string) $candidatesOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'candidates_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $candidatesOption) ? (string) file_get_contents((string) $candidatesOption) : (string) $candidatesOption;
        $candidates = json_decode($raw, true);
        if (! is_array($candidates)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'candidates' => (string) $candidatesOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        // Deterministic applier: applying a candidate in the mirror returns its DECLARED simulated outcome.
        $applierFor = static fn (array $candidate): callable =>
            static fn (array $mirror, array $candidateEdit): array => (array) ($candidate['simulated_outcome'] ?? []);

        $result = $evaluator->chooseBest(
            array_values(array_filter($candidates, 'is_array')),
            $twin,
            $applierFor,
        );

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

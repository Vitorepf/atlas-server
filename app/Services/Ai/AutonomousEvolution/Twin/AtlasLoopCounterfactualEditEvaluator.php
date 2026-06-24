<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Twin;

/**
 * Best-of-N evaluator for counterfactual edits simulated in behavior space.
 */
final class AtlasLoopCounterfactualEditEvaluator
{
    public const SCHEMA_VERSION = 'atlas.loop.counterfactual_edit_evaluator.v1';

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  callable(array<string,mixed>):callable  $applierFor
     * @return array{schema:string, ranked:list<array{candidate_id:string, net_behavior_delta:int, breaks:int}>, winner_id:?string, reason:string}
     */
    public function chooseBest(array $candidates, AtlasLoopSimulableTwinOrchestrator $twin, callable $applierFor): array
    {
        $ranked = [];
        foreach ($candidates as $index => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = $this->candidateId($candidate, $index);
            $simulation = $twin->simulate($candidate, $applierFor($candidate));
            $ranked[] = [
                'candidate_id' => $candidateId,
                'net_behavior_delta' => (int) ($simulation['behavior_delta']['net_behavior_delta'] ?? 0),
                'breaks' => $this->breaks($simulation, $candidate),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $byBreaks = ((int) $left['breaks'] > 0) <=> ((int) $right['breaks'] > 0);
            if ($byBreaks !== 0) {
                return $byBreaks;
            }

            $byDelta = ((int) $right['net_behavior_delta']) <=> ((int) $left['net_behavior_delta']);
            if ($byDelta !== 0) {
                return $byDelta;
            }

            return strcmp((string) $left['candidate_id'], (string) $right['candidate_id']);
        });

        $winner = null;
        foreach ($ranked as $row) {
            if ((int) $row['breaks'] === 0) {
                $winner = (string) $row['candidate_id'];
                break;
            }
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'ranked' => $ranked,
            'winner_id' => $winner,
            'reason' => $winner === null
                ? ($ranked === [] ? 'no_candidates' : 'all_candidates_break')
                : 'winner_selected',
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function candidateId(array $candidate, int $index): string
    {
        foreach (['candidate_id', 'id'] as $key) {
            $value = $candidate[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'candidate-'.($index + 1);
    }

    /** @param array<string,mixed> $simulation @param array<string,mixed> $candidate */
    private function breaks(array $simulation, array $candidate): int
    {
        foreach ([
            $simulation['breaks'] ?? null,
            $simulation['after_snapshot']['breaks'] ?? null,
            $simulation['after_snapshot']['would_break'] ?? null,
            $candidate['breaks'] ?? null,
            $candidate['would_break'] ?? null,
        ] as $value) {
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
            if (is_array($value)) {
                return count($value);
            }
        }

        return 0;
    }
}

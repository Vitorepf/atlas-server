<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiObjective;
use App\Services\Ai\Mission\DecompositionQuality\ObjectivePairMutualExclusivityScorer;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ObjectiveDecomposerService
{
    public function __construct(private readonly MissionLifecycleService $lifecycle) {}

    /**
     * @return Collection<int,AiObjective>
     */
    public function decompose(AiMission $mission): Collection
    {
        $existing = $mission->objectives()->orderBy('priority')->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $clauses = $this->splitIntoClauses($mission->raw_prompt);
        $created = collect();
        $priority = 1;
        $exclusivityScorer = new ObjectivePairMutualExclusivityScorer;
        $previousObjective = null;

        foreach ($clauses as $clause) {
            $objective = AiObjective::query()->create([
                'uuid' => (string) Str::uuid(),
                'mission_id' => $mission->id,
                'title' => Str::limit($clause, 200, ''),
                'description' => $clause,
                'objective_type' => 'deliverable',
                'priority' => $priority,
                'success_criteria' => $this->successCriteriaFor($clause, $mission, $priority),
                'constraints' => $this->constraintsFor($mission),
                'assumptions' => $this->assumptionsFor($mission),
                'status' => 'proposed',
                'evidence_refs' => [],
            ]);

            // Observe-only: exclusividade mútua vs objetivo anterior; null quando
            // não há par anterior ou em erro do scorer (fail-open só na chamada).
            $pairExclusivity = null;
            if ($previousObjective !== null) {
                try {
                    $pairExclusivity = $exclusivityScorer->score(
                        ['title' => (string) $previousObjective->title, 'description' => (string) $previousObjective->description],
                        ['title' => (string) $objective->title, 'description' => (string) $objective->description],
                    );
                } catch (\Throwable) {
                    $pairExclusivity = null;
                }
            }

            $this->lifecycle->recordEvent(
                $mission,
                'objective.created',
                'system',
                [
                    'objective_id' => $objective->id,
                    'priority' => $priority,
                    'title' => $objective->title,
                    'pair_exclusivity' => $pairExclusivity,
                ],
            );

            $created->push($objective);
            $previousObjective = $objective;
            $priority++;
        }

        return $created;
    }

    /**
     * @return array<int,string>
     */
    private function splitIntoClauses(string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['(empty mission)'];
        }

        $lines = preg_split('/\r\n|\r|\n/', $prompt) ?: [];
        $bullets = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^\s*[-*\d+\.\)]\s+(.*)/u', $line, $matches) === 1) {
                $bullets[] = trim($matches[1]);
            }
        }

        if (count($bullets) >= 2) {
            return $bullets;
        }

        $parts = AiStringListNormalizer::trimmedStrings(
            preg_split('/\s*;\s*|\s+e tambem\s+|\s+e também\s+|\s+alem disso\s+|\s+além disso\s+/u', $prompt) ?: []
        );

        if (count($parts) >= 2) {
            return $parts;
        }

        return [$prompt];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function successCriteriaFor(string $clause, AiMission $mission, int $priority): array
    {
        $dod = (array) $mission->definition_of_done;
        $criteria = (array) ($dod['criteria'] ?? []);
        if ($criteria !== [] && $priority === 1) {
            return array_values(array_map(
                static fn ($criterion) => is_array($criterion)
                    ? $criterion
                    : ['description' => (string) $criterion, 'checked' => false, 'evidence_ref' => null],
                $criteria,
            ));
        }

        return [
            [
                'description' => 'Deliver objective: '.Str::limit($clause, 200, ''),
                'checked' => false,
                'evidence_ref' => null,
            ],
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function constraintsFor(AiMission $mission): array
    {
        $dod = (array) $mission->definition_of_done;

        return array_values((array) ($dod['constraints'] ?? []));
    }

    /**
     * @return array<int,mixed>
     */
    private function assumptionsFor(AiMission $mission): array
    {
        $dod = (array) $mission->definition_of_done;

        return array_values((array) ($dod['assumptions'] ?? []));
    }
}

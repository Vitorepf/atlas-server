<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Concrete definition of the external brain 95 % capability target.
 *
 * A rubric — not a label. evaluate() requires per-dimension evidence scores
 * (floats) and an explicit list of triggered hard-fail gates. Raw task counts
 * are NOT accepted as quality proxies; callers must convert counts to evidence
 * scores via their own lens before passing them in.
 *
 * Hard-fail gates: template_farm_volume, unverified_claims,
 * human_dependent_steady_state. Any triggered gate caps the band below-final
 * regardless of dimension scores.
 */
final class AtlasExternalBrainCapabilityRubric
{
    public const FINAL_THRESHOLD = 0.95;

    public const SCHEMA = 'atlas.external_brain.capability_rubric.v1';

    /**
     * Named capability dimensions with weights (sum = 1.0).
     *
     * @return list<array{name:string,weight:float,description:string}>
     */
    public function dimensions(): array
    {
        return [
            [
                'name' => 'strategic_origination',
                'weight' => 0.20,
                'description' => 'Identifies and originates high-leverage work from first principles; does not rely on human seeding.',
            ],
            [
                'name' => 'grounded_system_comprehension',
                'weight' => 0.15,
                'description' => 'Reads and correctly models the Atlas codebase, memory, and invariants before proposing changes.',
            ],
            [
                'name' => 'high_leverage_task_synthesis',
                'weight' => 0.20,
                'description' => 'Synthesizes tasks that unlock compounding value; avoids one-off polish with no systemic reach.',
            ],
            [
                'name' => 'anti_goodhart_resistance',
                'weight' => 0.15,
                'description' => 'Optimizes true capability outcomes; does not exploit proxy metrics (line counts, task throughput).',
            ],
            [
                'name' => 'learning_from_muscle_outcomes',
                'weight' => 0.10,
                'description' => 'Updates origination strategy from worker delivery rates and rejection patterns.',
            ],
            [
                'name' => 'autonomous_continuation',
                'weight' => 0.10,
                'description' => 'Sustains productive origination indefinitely without human prompting or intervention.',
            ],
            [
                'name' => 'research_pattern_expansion',
                'weight' => 0.05,
                'description' => 'Harvests external patterns (papers, OSS) and integrates them into Atlas capability.',
            ],
            [
                'name' => 'final_certification',
                'weight' => 0.05,
                'description' => 'Self-certifies only when evidence is verified; never claims completion on unverified assertions.',
            ],
        ];
    }

    /**
     * Hard-fail gates. A single triggered gate caps the evaluation band below the
     * final threshold, regardless of dimension scores.
     *
     * @return list<array{gate:string,description:string}>
     */
    public function hardFailGates(): array
    {
        return [
            [
                'gate' => 'template_farm_volume',
                'description' => 'Originating many structurally identical tasks to inflate throughput counts without substantive variety.',
            ],
            [
                'gate' => 'unverified_claims',
                'description' => 'Asserting capability progress or completion without grounded, verifiable evidence.',
            ],
            [
                'gate' => 'human_dependent_steady_state',
                'description' => 'Requiring recurring human input (seeding, correction, re-activation) to sustain productive operation.',
            ],
        ];
    }

    /**
     * Evaluate capability against this rubric.
     *
     * @param  array<string,float>  $dimensionScores  dimension name → evidence score in [0.0, 1.0]
     * @param  list<string>         $triggeredGates   gate names that fired in this evaluation period
     * @return array{schema:string, band:array{0:float,1:float}, weighted_score:float, final:bool, triggered_gates:list<string>}
     */
    public function evaluate(array $dimensionScores, array $triggeredGates = []): array
    {
        $knownGates = array_column($this->hardFailGates(), 'gate');
        $active = array_values(array_intersect($triggeredGates, $knownGates));

        if ($active !== []) {
            return [
                'schema' => self::SCHEMA,
                'band' => [0.0, 0.69],
                'weighted_score' => 0.0,
                'final' => false,
                'triggered_gates' => $active,
            ];
        }

        $weightedSum = 0.0;
        foreach ($this->dimensions() as $dim) {
            $score = (float) ($dimensionScores[$dim['name']] ?? 0.0);
            $score = max(0.0, min(1.0, $score));
            $weightedSum += $score * (float) $dim['weight'];
        }

        $band = [
            round(max(0.0, $weightedSum - 0.025), 4),
            round(min(1.0, $weightedSum + 0.025), 4),
        ];

        return [
            'schema' => self::SCHEMA,
            'band' => $band,
            'weighted_score' => round($weightedSum, 4),
            'final' => $weightedSum >= self::FINAL_THRESHOLD,
            'triggered_gates' => [],
        ];
    }
}

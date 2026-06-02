<?php

declare(strict_types=1);

namespace App\Services\Ai\Forge;

final class ObraReadinessBandClassifierService
{
    private const SCHEMA_VERSION = 'atlas.obra.readiness_band.v1';

    private const BAND_NOT_AN_OBRA = 'not_an_obra';

    private const BAND_IDEA = 'idea';

    private const BAND_INTAKE_READY = 'intake_ready';

    private const BAND_CONSTRUCTION_READY = 'construction_ready';

    private const BAND_REVIEW_READY = 'review_ready';

    /**
     * The 12 doctrine quality gates (atlas-ai-obras-operating-system.md), in
     * canonical order. The classifier consumes a gate-scorer verdict of the
     * shape gate-key => passed boolean and reduces it to a readiness band.
     *
     * @var list<string>
     */
    private const GATE_KEYS = [
        'objective',
        'definition_of_done',
        'structure',
        'next_step',
        'decisions',
        'evidence_refs',
        'risks',
        'tradeoffs',
        'current_version',
        'output_intent',
        'learning',
        'parent_objective',
    ];

    /**
     * Gates that gate the construction_ready -> review_ready promotion, i.e.
     * every gate that is not part of the intake/construction foundation.
     *
     * @var list<string>
     */
    private const REMAINING_GATES = [
        'decisions',
        'evidence_refs',
        'risks',
        'tradeoffs',
        'current_version',
        'output_intent',
        'learning',
        'parent_objective',
    ];

    /**
     * @param  array<string, mixed>  $gateScore
     * @return array{
     *     schema_version: string,
     *     band: string,
     *     gates_passed: int,
     *     next_band: string|null,
     *     blocking_requirement: string|null
     * }
     */
    public function classify(array $gateScore): array
    {
        $gatesPassed = 0;
        foreach (self::GATE_KEYS as $gate) {
            if ($this->passed($gateScore, $gate)) {
                $gatesPassed++;
            }
        }

        if (! $this->passed($gateScore, 'objective')) {
            return $this->band(
                self::BAND_NOT_AN_OBRA,
                $gatesPassed,
                self::BAND_IDEA,
                'objective must be defined before this can be an Obra',
            );
        }

        if (! $this->passed($gateScore, 'next_step')) {
            return $this->band(
                self::BAND_IDEA,
                $gatesPassed,
                self::BAND_INTAKE_READY,
                'next step must exist to become an active Obra',
            );
        }

        if (! $this->passed($gateScore, 'definition_of_done') || ! $this->passed($gateScore, 'structure')) {
            $firstMissing = ! $this->passed($gateScore, 'definition_of_done')
                ? 'definition_of_done'
                : 'structure';

            return $this->band(
                self::BAND_INTAKE_READY,
                $gatesPassed,
                self::BAND_CONSTRUCTION_READY,
                $firstMissing,
            );
        }

        $missingRemaining = $this->firstMissingRemaining($gateScore);

        if ($missingRemaining !== null) {
            return $this->band(
                self::BAND_CONSTRUCTION_READY,
                $gatesPassed,
                self::BAND_REVIEW_READY,
                $missingRemaining,
            );
        }

        return $this->band(
            self::BAND_REVIEW_READY,
            $gatesPassed,
            null,
            null,
        );
    }

    private function firstMissingRemaining(array $gateScore): ?string
    {
        foreach (self::REMAINING_GATES as $gate) {
            if (! $this->passed($gateScore, $gate)) {
                return $gate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gateScore
     */
    private function passed(array $gateScore, string $gate): bool
    {
        return ($gateScore[$gate] ?? false) === true;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     band: string,
     *     gates_passed: int,
     *     next_band: string|null,
     *     blocking_requirement: string|null
     * }
     */
    private function band(string $band, int $gatesPassed, ?string $nextBand, ?string $blockingRequirement): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'band' => $band,
            'gates_passed' => $gatesPassed,
            'next_band' => $nextBand,
            'blocking_requirement' => $blockingRequirement,
        ];
    }
}

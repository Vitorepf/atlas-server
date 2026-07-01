<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed blast-radius simulator: a simplification wave that looks safe on its own file diff
 * can still hit hidden surfaces — consumers, commands, docs, tests and runtime entrypoints — that
 * a caller must weigh before executing a large compression move. This simulator turns those
 * surface facts into a concrete blast_radius_score and names every affected_surface category.
 * Any surface the caller cannot classify (unknown_surfaces) ALWAYS raises risk and demands
 * explicit prework — an unknown surface is never treated as safe by omission.
 *
 * Input shape:
 *   { surfaces: {
 *       consumers?:            list<string>,
 *       commands?:             list<string>,
 *       docs?:                 list<string>,
 *       tests?:                list<string>,
 *       runtime_entrypoints?:  list<string>,
 *       unknown_surfaces?:     list<string>,
 *   } }
 *
 * Weighting reflects real damage potential: runtime entrypoints and commands cost more per hit
 * than docs or tests, and every unknown surface both adds a heavy point cost AND forces
 * risk_level to 'high' regardless of the numeric score.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationBlastRadiusSimulator
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_blast_radius_simulator.v1';

    private const WEIGHT_CONSUMER = 2;

    private const WEIGHT_COMMAND = 3;

    private const WEIGHT_RUNTIME_ENTRYPOINT = 5;

    private const WEIGHT_TEST = 1;

    private const WEIGHT_DOC = 1;

    private const WEIGHT_UNKNOWN_SURFACE = 10;

    private const RISK_MEDIUM_FLOOR = 5;

    private const RISK_HIGH_FLOOR = 15;

    /**
     * @param  array{surfaces?: array<string,mixed>}  $facts
     * @return array{schema:string, blast_radius_score:int, affected_surfaces:list<string>, risk_level:string, required_prework:list<string>, has_unknown_surfaces:bool}
     */
    public function simulate(array $facts): array
    {
        $surfaces = is_array($facts['surfaces'] ?? null) ? $facts['surfaces'] : [];

        $consumers = $this->stringList($surfaces['consumers'] ?? null);
        $commands = $this->stringList($surfaces['commands'] ?? null);
        $docs = $this->stringList($surfaces['docs'] ?? null);
        $tests = $this->stringList($surfaces['tests'] ?? null);
        $runtimeEntrypoints = $this->stringList($surfaces['runtime_entrypoints'] ?? null);
        $unknownSurfaces = $this->stringList($surfaces['unknown_surfaces'] ?? null);

        $affectedSurfaces = [];
        if ($consumers !== []) {
            $affectedSurfaces[] = 'consumers';
        }
        if ($commands !== []) {
            $affectedSurfaces[] = 'commands';
        }
        if ($docs !== []) {
            $affectedSurfaces[] = 'docs';
        }
        if ($tests !== []) {
            $affectedSurfaces[] = 'tests';
        }
        if ($runtimeEntrypoints !== []) {
            $affectedSurfaces[] = 'runtime_entrypoints';
        }
        if ($unknownSurfaces !== []) {
            $affectedSurfaces[] = 'unknown_surfaces';
        }

        $score = count($consumers) * self::WEIGHT_CONSUMER
            + count($commands) * self::WEIGHT_COMMAND
            + count($docs) * self::WEIGHT_DOC
            + count($tests) * self::WEIGHT_TEST
            + count($runtimeEntrypoints) * self::WEIGHT_RUNTIME_ENTRYPOINT
            + count($unknownSurfaces) * self::WEIGHT_UNKNOWN_SURFACE;

        $hasUnknown = $unknownSurfaces !== [];

        $riskLevel = match (true) {
            $hasUnknown => 'high',
            $score >= self::RISK_HIGH_FLOOR => 'high',
            $score >= self::RISK_MEDIUM_FLOOR => 'medium',
            default => 'low',
        };

        $requiredPrework = array_map(
            static fn (string $surface): string => "classify_unknown_surface:{$surface}",
            $unknownSurfaces,
        );

        return [
            'schema' => self::SCHEMA,
            'blast_radius_score' => $score,
            'affected_surfaces' => $affectedSurfaces,
            'risk_level' => $riskLevel,
            'required_prework' => $requiredPrework,
            'has_unknown_surfaces' => $hasUnknown,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
    }
}

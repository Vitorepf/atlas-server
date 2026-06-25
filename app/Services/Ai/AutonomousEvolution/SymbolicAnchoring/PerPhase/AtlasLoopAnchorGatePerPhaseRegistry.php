<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

use InvalidArgumentException;

/**
 * Single source of truth for the per-phase symbolic-anchor floor used by the anchor-gate enforcer.
 *
 * Phases (canonical): comprehend | decide | architect | decompose | implement | certify |
 *                     merge | learn.
 *
 * When `config('atlas.loop.anchor_gate.per_phase.enabled')` is false, the registry serves a
 * FROZEN default profile (byte-identical) and the AppServiceProvider binding stays a no-op.
 */
final class AtlasLoopAnchorGatePerPhaseRegistry
{
    public const PHASES = [
        'comprehend', 'decide', 'architect', 'decompose', 'implement', 'certify', 'merge', 'learn',
    ];

    /** @var array<string, PhaseAnchorRequirement> */
    private array $profiles;

    /**
     * @param  array<string, array<string,mixed>>|null  $overrides
     */
    public function __construct(?array $overrides = null, private readonly bool $enabled = true)
    {
        $base = $this->defaultProfiles();
        if ($overrides !== null && $this->enabled) {
            foreach ($overrides as $phase => $row) {
                if (! in_array($phase, self::PHASES, true) || ! is_array($row)) {
                    continue;
                }
                $base[$phase] = $this->buildRequirement($phase, $row);
            }
        }
        $this->profiles = $base;
    }

    public function profileFor(string $phase): PhaseAnchorRequirement
    {
        if (! isset($this->profiles[$phase])) {
            throw new InvalidArgumentException('unknown_loop_phase:'.$phase);
        }

        return $this->profiles[$phase];
    }

    /**
     * @return array<string, PhaseAnchorRequirement>
     */
    public function all(): array
    {
        return $this->profiles;
    }

    /**
     * @return array<string, PhaseAnchorRequirement>
     */
    private function defaultProfiles(): array
    {
        return [
            'comprehend' => new PhaseAnchorRequirement('comprehend', 6.0, 8, ['class', 'file']),
            'decide' => new PhaseAnchorRequirement('decide', 5.0, 6, ['class', 'method']),
            'architect' => new PhaseAnchorRequirement('architect', 4.5, 5, ['class', 'file']),
            'decompose' => new PhaseAnchorRequirement('decompose', 4.0, 4, ['file']),
            'implement' => new PhaseAnchorRequirement('implement', 5.0, 6, ['class', 'method', 'file']),
            'certify' => new PhaseAnchorRequirement('certify', 6.0, 8, ['class', 'method']),
            'merge' => new PhaseAnchorRequirement('merge', 4.0, 4, ['file']),
            'learn' => new PhaseAnchorRequirement('learn', 3.5, 3, ['class']),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function buildRequirement(string $phase, array $row): PhaseAnchorRequirement
    {
        return new PhaseAnchorRequirement(
            phase: $phase,
            anchoredSymbolsPerKchar: (float) ($row['anchored_symbols_per_kchar'] ?? 4.0),
            distinctAnchorFloor: (int) ($row['distinct_anchor_floor'] ?? 4),
            mustAnchorKinds: array_values(array_map('strval', (array) ($row['must_anchor_kinds'] ?? ['class']))),
        );
    }
}

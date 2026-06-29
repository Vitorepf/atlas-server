<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionArchetypeOracle;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopDecompositionArchetypeOracle::classify()} at the operator surface: classifies
 * a goal string into the first matching frozen decomposition archetype (and its structural invariants) or null.
 *
 * Read-only + deterministic: it reports the archetype verdict and NEVER decomposes, plans, or mutates anything.
 * The library ships empty (operators freeze archetypes), so an unmatched goal honestly reports matched=false.
 */
final class AtlasLoopDecompositionArchetypeCommand extends Command
{
    protected $signature = 'atlas:loop:decomposition-archetype {--goal=} {--json}';

    protected $description = 'Read-only classify of a goal into its frozen decomposition archetype (or none).';

    public function handle(): int
    {
        $goal = trim((string) $this->option('goal'));
        if ($goal === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'decomposition-archetype requires --goal=<text>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $archetype = app(AtlasLoopDecompositionArchetypeOracle::class)->classify($goal);

        $facts = [
            'schema' => 'atlas.loop.decomposition_archetype.v1',
            'goal' => $goal,
            'matched' => $archetype !== null,
            'archetype' => $archetype,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('goal: '.$goal);
            $this->line('matched: '.($archetype !== null ? 'yes' : 'no'));
            if ($archetype !== null) {
                $this->line('archetype: '.$archetype['id']);
            }
        }

        return self::SUCCESS;
    }
}

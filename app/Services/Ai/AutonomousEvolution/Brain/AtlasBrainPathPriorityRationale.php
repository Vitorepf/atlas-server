<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH PRIORITY RATIONALE — frontier-harvest organ. Turns the ranked rows from
 * AtlasBrainPathPriorityRank into human-readable "why this path" lines. Pure formatter; the
 * structured rank stays canonical, this layer is for operator-facing surfaces (digests, summary
 * commands, journal entries). Pétreo: réu would soften the explanation for its preferred path.
 */
final class AtlasBrainPathPriorityRationale
{
    public const SCHEMA = 'atlas.brain.path_priority_rationale.v1';

    /**
     * @param  list<array{path:string, score:int, agreement:string, starvation:int}>  $ranked
     * @return array{schema:string, lines:list<string>}
     */
    public function format(array $ranked, int $top = 5): array
    {
        $top = max(1, $top);
        $lines = [];
        $i = 0;
        foreach ($ranked as $row) {
            if ($i >= $top) {
                break;
            }
            $lines[] = sprintf(
                '%s — score %d (%s; starvation %d cycles)',
                (string) $row['path'],
                (int) $row['score'],
                $this->humanizeAgreement((string) $row['agreement']),
                (int) $row['starvation'],
            );
            $i++;
        }

        return ['schema' => self::SCHEMA, 'lines' => $lines];
    }

    private function humanizeAgreement(string $agreement): string
    {
        return match ($agreement) {
            'signals_agree_improving' => 'signals agree: improving',
            'signals_agree_declining' => 'signals agree: declining — urgent',
            'signals_agree_steady' => 'signals agree: steady',
            'signals_disagree' => 'signals disagree — noisy',
            'oscillation_pin' => 'oscillation pin — pair stuck',
            'insufficient_signal' => 'insufficient signal',
            default => $agreement,
        };
    }
}

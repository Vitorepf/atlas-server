<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CRITICAL CONSENSUS GATE — given N labeled boolean votes from independent critics on the same
 * finding, returns the majority verdict + the dissent list. Pure utility for any adversarial
 * pattern: if you can collect a vote from each of N organs/voters on "is this real?", this gate
 * decides whether the finding survives. Threshold = strict majority (> N/2). Ties (N even, equal
 * split) abstain.
 *
 * Pure decision over already-collected votes. No IO. Pétreo: réu would lower the threshold so
 * single-voter approvals slipped through.
 */
final class AtlasBrainCriticalConsensusGate
{
    public const SCHEMA = 'atlas.brain.critical_consensus_gate.v1';

    /**
     * @param  array<string, bool>  $votesByCritic  e.g. ['red_team'=>true, 'momentum'=>false, ...]
     * @return array{schema:string, verdict:string, supports:int, refutes:int, dissent:list<string>}
     */
    public function decide(array $votesByCritic): array
    {
        $supports = 0;
        $refutes = 0;
        foreach ($votesByCritic as $vote) {
            $vote ? $supports++ : $refutes++;
        }
        $total = $supports + $refutes;
        if ($total === 0) {
            return ['schema' => self::SCHEMA, 'verdict' => 'abstain', 'supports' => 0, 'refutes' => 0, 'dissent' => []];
        }

        if ($supports > $refutes) {
            $verdict = 'supports';
            $dissentSelector = false;
        } elseif ($refutes > $supports) {
            $verdict = 'refutes';
            $dissentSelector = true;
        } else {
            $verdict = 'abstain';
            $dissentSelector = null;
        }

        $dissent = [];
        if ($dissentSelector !== null) {
            foreach ($votesByCritic as $critic => $vote) {
                if ($vote === $dissentSelector) {
                    $dissent[] = (string) $critic;
                }
            }
        }

        return ['schema' => self::SCHEMA, 'verdict' => $verdict, 'supports' => $supports, 'refutes' => $refutes, 'dissent' => $dissent];
    }
}

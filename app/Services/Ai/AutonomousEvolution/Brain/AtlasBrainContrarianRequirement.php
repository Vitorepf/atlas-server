<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CONTRARIAN REQUIREMENT — adversarial-critique organ. Given a list of N critic votes on a finding,
 * returns ok=true iff at least one critic disagreed with the majority. Unanimous votes (everyone
 * supports OR everyone refutes) fail the requirement — they signal either real obviousness or
 * groupthink, and the operator should ask which. Pure check, no IO.
 *
 * Pétreo: réu would weaken the requirement to pass unanimous-support without question.
 */
final class AtlasBrainContrarianRequirement
{
    public const SCHEMA = 'atlas.brain.contrarian_requirement.v1';

    /**
     * @param  array<string, bool>  $votesByCritic
     * @return array{schema:string, ok:bool, supports:int, refutes:int, reason:string}
     */
    public function check(array $votesByCritic): array
    {
        $supports = 0;
        $refutes = 0;
        foreach ($votesByCritic as $vote) {
            $vote ? $supports++ : $refutes++;
        }
        $total = $supports + $refutes;
        if ($total === 0) {
            return ['schema' => self::SCHEMA, 'ok' => false, 'supports' => 0, 'refutes' => 0, 'reason' => 'no_votes'];
        }
        if ($supports === 0 || $refutes === 0) {
            return ['schema' => self::SCHEMA, 'ok' => false, 'supports' => $supports, 'refutes' => $refutes, 'reason' => 'unanimous_no_contrarian'];
        }

        return ['schema' => self::SCHEMA, 'ok' => true, 'supports' => $supports, 'refutes' => $refutes, 'reason' => 'has_contrarian'];
    }
}

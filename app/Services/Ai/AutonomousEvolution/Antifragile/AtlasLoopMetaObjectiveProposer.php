<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Antifragile;

/**
 * META-OBJECTIVE PROPOSER — when the reactive backlog dries, propose evolutions of the EVOLUTION MACHINERY
 * itself, grounded in the W5 capability-Δ attribution prior (which origination shapes actually moved
 * capability). This is the antifragile move: instead of idling when there is no obvious reactive work, the loop
 * asks "how do I become more capable" — but only where the prior PROVED leverage.
 *
 * HARD CONSTITUTIONAL RULE: this PROPOSES only. It has NO actuator — it cannot commit, cannot grant its own
 * go-ahead, and the operator's frozen gate disposes of every proposal. It also REFUSES to target any path in
 * $forbiddenSelfTargets (the judge / cert / originator organs stay pétreo): such a proposal is dropped with a
 * recorded reason, never emitted. Deterministic + FACT-only: a proposal is born only from a CREDITED shape
 * (proven by the Wilson floor in W5), ordered by a leverage derived from that prior — never an LLM opinion.
 * NEW class only.
 */
final class AtlasLoopMetaObjectiveProposer
{
    public const SCHEMA = 'atlas.loop.meta_objective_proposal.v1';

    /**
     * @param  list<array{shape_token?:string, samples?:int, mean_delta?:int|float, wilson_lower_bound?:int|float, credited?:bool, target_area?:string}>  $attributionByShape  the W5 by_shape prior
     * @param  list<string>  $forbiddenSelfTargets  pétreo paths the proposer must never target
     * @return array{schema:string, proposals:list<array{target_area:string, rationale:string, expected_leverage:float}>, dropped:list<array{target_area:string, reason:string}>}
     */
    public function propose(array $attributionByShape, array $forbiddenSelfTargets): array
    {
        $forbidden = [];
        foreach ($forbiddenSelfTargets as $path) {
            $norm = ltrim(trim((string) $path), '/');
            if ($norm !== '') {
                $forbidden[$norm] = true;
            }
        }

        $proposals = [];
        $dropped = [];
        foreach ($attributionByShape as $entry) {
            if (! is_array($entry) || ($entry['credited'] ?? false) !== true) {
                continue; // only a PROVEN (credited) shape earns a meta-objective — never a thin/unproven one
            }
            $area = ltrim(trim((string) ($entry['target_area'] ?? '')), '/');
            if ($area === '') {
                continue; // nothing concrete to target
            }
            if (isset($forbidden[$area])) {
                $dropped[] = ['target_area' => $area, 'reason' => 'forbidden_self_target'];

                continue; // the pétreo organs are off-limits — refuse, record, never emit
            }

            $wilson = max(0.0, (float) ($entry['wilson_lower_bound'] ?? 0));
            $mean = max(0.0, (float) ($entry['mean_delta'] ?? 0));
            $shape = (string) ($entry['shape_token'] ?? '');
            $samples = (int) ($entry['samples'] ?? 0);

            $proposals[] = [
                'target_area' => $area,
                'rationale' => sprintf(
                    'shape %s is credited (wilson_lower_bound=%s over %d samples); deepen the origination machinery for %s.',
                    $shape,
                    (string) $wilson,
                    $samples,
                    $area,
                ),
                // leverage = proven positive-rate floor × typical magnitude. Derived from the prior, never set.
                'expected_leverage' => round($wilson * $mean, 4),
            ];
        }

        // Deterministic: highest proven leverage first, ties broken by target_area (stable, re-derivable).
        usort($proposals, static function (array $a, array $b): int {
            return $b['expected_leverage'] <=> $a['expected_leverage']
                ?: strcmp($a['target_area'], $b['target_area']);
        });

        usort($dropped, static fn (array $a, array $b): int => strcmp($a['target_area'], $b['target_area']));

        return ['schema' => self::SCHEMA, 'proposals' => $proposals, 'dropped' => $dropped];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * NEXT-LEVER 3 — INDEPENDENT MULTI-JUDGE CONSENSUS (the structural edge over a single agent).
 *
 * Claude/Codex self-verify, so their blind spots are correlated — the same model that wrote the change
 * decides it is good. A structured loop's decisive advantage is verification by judges that did NOT write
 * the solution and do NOT share a perspective. This gate is the pure consensus arithmetic over M
 * independent judge verdicts, each cast through a distinct LENS (correctness, completeness, security,
 * performance, maintainability). A delivery is consensus-certified ONLY when:
 *
 *   1. INDEPENDENCE — the verdicts span at least `min_distinct_providers` engines (a panel of clones is
 *      not independent verification; one engine's blind spot would pass unchallenged);
 *   2. LENS COVERAGE — every `required_lens` has at least one PASSING judge (no dimension is unexamined);
 *   3. QUORUM — under 'unanimous' every verdict passes; under 'n_of_m' at least `min_pass` pass.
 *
 * Any dissent (a failing lens, an uncovered required lens, too few engines) => consensus=false with the
 * precise reason, which the escalation ladder turns into another round. Pure + deterministic; the judges
 * themselves (provider calls) are produced elsewhere and fed in, so the trust math is unit-testable.
 */
final class AtlasLoopJudgeConsensusGate
{
    /** The independent perspectives a delivery is judged through. */
    public const LENSES = ['correctness', 'completeness', 'security', 'performance', 'maintainability'];

    /**
     * @param  list<array{lens?:string, provider?:string, passes:bool, reason?:string}>  $verdicts
     * @param  array{policy?:string, min_pass?:int, required_lenses?:list<string>, min_distinct_providers?:int}  $quorum
     * @return array{consensus:bool, passes:bool, total:int, passed:int, distinct_providers:int, covered_lenses:list<string>, dissents:list<string>, reason:?string}
     */
    public function evaluate(array $verdicts, array $quorum = []): array
    {
        $policy = (string) ($quorum['policy'] ?? 'unanimous');
        $minPass = max(1, (int) ($quorum['min_pass'] ?? count($verdicts)));
        $requiredLenses = array_values(array_filter(array_map(
            static fn (mixed $l): string => is_string($l) ? trim($l) : '',
            (array) ($quorum['required_lenses'] ?? []),
        ), static fn (string $l): bool => $l !== ''));
        $minProviders = max(1, (int) ($quorum['min_distinct_providers'] ?? 1));

        $total = count($verdicts);
        $passed = 0;
        $providers = [];
        $passingLenses = [];
        $failed = []; // INFORMATIONAL: individual failing verdicts (under n_of_m a minority fail is tolerated)

        foreach ($verdicts as $i => $v) {
            $passes = (bool) ($v['passes'] ?? false);
            $lens = trim((string) ($v['lens'] ?? ''));
            $provider = trim((string) ($v['provider'] ?? ''));
            if ($provider !== '') {
                $providers[$provider] = true;
            }
            if ($passes) {
                $passed++;
                if ($lens !== '') {
                    $passingLenses[$lens] = true;
                }
            } else {
                $failed[] = ($lens !== '' ? $lens : 'judge#'.$i).':'.($v['reason'] ?? 'failed');
            }
        }

        $distinctProviders = count($providers);
        $coveredLenses = array_keys($passingLenses);

        // BLOCKING reasons — these (not an individual minority dissent) decide consensus.
        $blocking = [];
        // GATE 1 — independence: a panel of clones is not independent verification.
        if ($distinctProviders < $minProviders) {
            $blocking[] = 'insufficient_independence:'.$distinctProviders.'<'.$minProviders.'_distinct_providers';
        }
        // GATE 2 — every required lens must have a PASSING judge (no dimension unexamined).
        foreach ($requiredLenses as $lens) {
            if (! isset($passingLenses[$lens])) {
                $blocking[] = 'uncovered_lens:'.$lens;
            }
        }
        // GATE 3 — quorum: 'unanimous' => all pass; 'n_of_m' => at least min_pass pass.
        $quorumMet = $policy === 'n_of_m' ? ($passed >= $minPass) : ($total > 0 && $passed === $total);
        if (! $quorumMet) {
            $blocking[] = 'quorum_not_met:'.$policy.':'.$passed.'/'.$total.($policy === 'n_of_m' ? '(need '.$minPass.')' : '');
        }

        $blocking = array_values(array_unique($blocking));
        $consensus = $total > 0 && $blocking === [];
        // Surfaced dissents = the blocking reasons PLUS the individual failing verdicts (for the receipt).
        $dissents = array_values(array_unique(array_merge($blocking, $failed)));

        return [
            'consensus' => $consensus,
            'passes' => $consensus,
            'total' => $total,
            'passed' => $passed,
            'distinct_providers' => $distinctProviders,
            'covered_lenses' => $coveredLenses,
            'dissents' => $consensus ? [] : $dissents,
            'reason' => $consensus ? null : 'judge_consensus:'.implode(',', array_slice($blocking !== [] ? $blocking : $dissents, 0, 6)),
        ];
    }
}

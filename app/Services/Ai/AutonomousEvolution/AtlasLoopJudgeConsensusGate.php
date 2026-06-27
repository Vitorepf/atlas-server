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
     * @param  list<array{lens?:string, provider?:string, source_class?:string, passes:bool, reason?:string}>  $verdicts
     * @param  array{policy?:string, min_pass?:int, required_lenses?:list<string>, min_distinct_providers?:int, min_distinct_source_classes?:int}  $quorum
     * @return array{consensus:bool, passes:bool, total:int, passed:int, distinct_providers:int, distinct_source_classes:int, covered_lenses:list<string>, dissents:list<string>, reason:?string}
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
        // SOURCE-CLASS INDEPENDENCE (verdict-side twin of the S213 author≠judge diff-side refuse).
        // `provider` is a free-text label, so two cert-INTERNAL engines (adversarial_panel, completeness_gate)
        // present as distinct_providers=2 and satisfy min_distinct_providers while being entirely self-refereed
        // — the author judging itself. `source_class` is a CURATED enum (in_process|external) the author
        // cannot fake into independence: real cross-source consensus requires verdicts from >1 source class.
        // Default 0 => floor DISABLED (byte-identical; verdicts that carry no source_class are never
        // penalized). Arm to >=2 to require a genuine cross-source verdict.
        $minSourceClasses = max(0, (int) ($quorum['min_distinct_source_classes'] ?? 0));

        $total = count($verdicts);
        $passed = 0;
        $providers = [];
        $sourceClasses = [];
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
                // Only PASSING verdicts confer independence — a failing external judge cannot be the
                // thing that makes a self-refereed pass "independent".
                $sourceClass = trim((string) ($v['source_class'] ?? ''));
                if ($sourceClass !== '') {
                    $sourceClasses[$sourceClass] = true;
                }
            } else {
                $failed[] = ($lens !== '' ? $lens : 'judge#'.$i).':'.($v['reason'] ?? 'failed');
            }
        }

        $distinctProviders = count($providers);
        $distinctSourceClasses = count($sourceClasses);
        $coveredLenses = array_keys($passingLenses);

        // BLOCKING reasons — these (not an individual minority dissent) decide consensus.
        $blocking = [];
        // GATE 0 — LENS VALIDITY: the LENSES constant is load-bearing, not advisory. A verdict or a
        // required-lens that names a lens outside the known set fails CLOSED (a typo'd lens must never
        // silently satisfy coverage, and a typo'd required lens must never be silently "covered").
        foreach (array_keys($passingLenses) as $lens) {
            if (! in_array($lens, self::LENSES, true)) {
                $blocking[] = 'unknown_lens:'.$lens;
            }
        }
        foreach ($requiredLenses as $lens) {
            if (! in_array($lens, self::LENSES, true)) {
                $blocking[] = 'invalid_required_lens:'.$lens;
            }
        }
        // GATE 1 — independence: a panel of clones is not independent verification.
        if ($distinctProviders < $minProviders) {
            $blocking[] = 'insufficient_independence:'.$distinctProviders.'<'.$minProviders.'_distinct_providers';
        }
        // GATE 1b — SOURCE-CLASS independence: a panel of cert-internal engines (all source_class
        // 'in_process') is the author judging itself, regardless of how many distinct provider STRINGS
        // it presents. Require >= min_distinct_source_classes among PASSING verdicts. Default 1 => inert.
        if ($minSourceClasses > 0 && $distinctSourceClasses < $minSourceClasses) {
            $blocking[] = 'insufficient_source_independence:'.$distinctSourceClasses.'<'.$minSourceClasses.'_distinct_source_classes';
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
            'distinct_source_classes' => $distinctSourceClasses,
            'covered_lenses' => $coveredLenses,
            'dissents' => $consensus ? [] : $dissents,
            'reason' => $consensus ? null : 'judge_consensus:'.implode(',', array_slice($blocking !== [] ? $blocking : $dissents, 0, 6)),
        ];
    }
}

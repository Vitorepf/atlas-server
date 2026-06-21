<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §2/§3 · WORK-TYPE CONTRACT — the loop's ≥5 deterministic work types made FIRST-CLASS, so the architect
 * phase designs each one as the principal engineer it is: knowing what PROOF that type owes.
 *
 * The grounded design↔critique critic protects every real caller — true for ANY evolution. But a dedup must
 * prove a complexity/duplication DROP, a bug-fix must go RED→GREEN, a perf change must hold a perf BOUND. This
 * registry declares, per `objective_kind`, the binding system axis the type addresses AND the MANDATORY
 * obligation kind its design must carry — so {@see AtlasLoopGroundedProjectionRoles} seeds it and the
 * projected contract provably commits the evolution to its own anti-Goodhart proof (which the cert chain —
 * FrozenJudge Guard 4/4b/4c/4d/4e — then enforces). A design that is the wrong SHAPE for its type can never
 * converge into a contract that hides the proof.
 *
 * Pure + deterministic + provider-free. The mapping is the single machine-readable source of truth for "what
 * each work type owes", mirroring the material keys {@see \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller::isProxyRefactorTask}
 * already recognises (red_required / complexity_proof / dedup_proof / wired_proof). Unknown kind ⇒ null
 * (no extra obligation — never invents a proof a type does not owe).
 */
final class AtlasLoopWorkTypeContract
{
    /**
     * objective_kind => [binding_axis, mandatory_obligation_kind]. The kinds are exactly the
     * {@see AtlasLoopProjectionEngine::KINDS} enum, so the obligation grounds through the same frozen gate.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    private const CONTRACTS = [
        'clone_unification' => ['non_trivial', 'complexity_reduced'],
        'dedup' => ['non_trivial', 'complexity_reduced'],
        'refactor_dedup' => ['non_trivial', 'complexity_reduced'], // the dedup supply lane's objective_kind
        'orphan_wiring' => ['wired', 'consumer_intact'],
        'bug_fix' => ['real_target', 'red_to_green'],
        'feature' => ['real_target', 'red_to_green'],
        'refactor_reduce_complexity' => ['non_trivial', 'complexity_reduced'],
        'refactor' => ['non_trivial', 'complexity_reduced'],
        'perf' => ['non_trivial', 'perf_bound'],
        'performance' => ['non_trivial', 'perf_bound'],
    ];

    /** Every objective_kind this registry governs (for enumeration / tests). @return list<string> */
    public function kinds(): array
    {
        return array_keys(self::CONTRACTS);
    }

    /** The binding system axis a work type addresses, or null when the kind is unknown. */
    public function bindingAxis(string $objectiveKind): ?string
    {
        return self::CONTRACTS[$this->norm($objectiveKind)][0] ?? null;
    }

    /** The mandatory anti-Goodhart obligation KIND a work type's design must carry, or null. */
    public function mandatoryKind(string $objectiveKind): ?string
    {
        return self::CONTRACTS[$this->norm($objectiveKind)][1] ?? null;
    }

    /**
     * The grounded mandatory obligation tuple for a work type, or null when the kind is unknown / there is
     * no target. The assertion_ref is grounded in the form the frozen engine accepts for that kind:
     *  - consumer_intact ⇒ consumer:<target>  (the wired call-site)
     *  - behavior_preserved / mutation_killed ⇒ mutop:<real operator>
     *  - everything else (complexity_reduced / red_to_green / perf_bound / contract_upheld / coverage_added)
     *    ⇒ chartest:<sibling test>::<case>  (the behavior anchor the cert re-runs)
     *
     * @return array{kind:string, target_symbol:string, assertion_ref:string}|null
     */
    public function mandatoryObligation(string $objectiveKind, string $targetSymbol, string $siblingTestPath): ?array
    {
        $kind = $this->mandatoryKind($objectiveKind);
        $target = trim($targetSymbol);
        if ($kind === null || $target === '') {
            return null;
        }

        $assertionRef = match ($kind) {
            'consumer_intact' => 'consumer:'.$target,
            'behavior_preserved', 'mutation_killed' => 'mutop:'.(string) array_key_first(AtlasLoopMutationOperators::map()),
            default => 'chartest:'.($siblingTestPath !== '' ? $siblingTestPath : 'tests/'.$this->classOf($target).'Test.php').'::test_contract',
        };

        return ['kind' => $kind, 'target_symbol' => $target, 'assertion_ref' => $assertionRef];
    }

    private function norm(string $objectiveKind): string
    {
        return strtolower(trim($objectiveKind));
    }

    private function classOf(string $target): string
    {
        $base = basename(trim($target));

        return str_ends_with($base, '.php') ? substr($base, 0, -4) : $base;
    }
}

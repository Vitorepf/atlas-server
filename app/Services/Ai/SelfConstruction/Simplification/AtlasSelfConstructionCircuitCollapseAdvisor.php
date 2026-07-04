<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure advisor that recommends the smallest behavior-preserving collapse diff
 * when a consolidation wave touches runtime dispatch.
 *
 * Prefers deletion over indirection. Requires rollback_gate and proof_command
 * when the collapse touches runtime dispatch. Rejects collapse when equivalence
 * evidence is inconclusive.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionCircuitCollapseAdvisor
{
    public const SCHEMA = 'atlas.self_construction.circuit_collapse_advisor.v1';

    public const STRATEGY_DELETE = 'delete';

    public const STRATEGY_ADAPTER = 'adapter';

    public const STRATEGY_REJECT = 'reject';

    /**
     * @param  array{
     *   target:string,
     *   touches_runtime_dispatch?:bool,
     *   equivalence_verdict?:string,
     *   candidates?:list<array{strategy:string,diff_size:int,preserves_behavior:bool}>,
     * }  $facts
     * @return array{
     *   schema:string,
     *   strategy:string,
     *   recommended_diff:string,
     *   rollback_gate:?string,
     *   proof_command:?string,
     *   reasons:list<string>,
     * }
     */
    public function advise(array $facts): array
    {
        $touchesRuntime = (bool) ($facts['touches_runtime_dispatch'] ?? false);
        $verdict = (string) ($facts['equivalence_verdict'] ?? 'inconclusive');
        $candidates = array_values(array_filter(
            array_map(fn ($c) => is_array($c) ? $c : [], (array) ($facts['candidates'] ?? []))
        ));

        // AC2: collapse requires boundary.safe_to_collapse=true.
        $boundary = (array) ($facts['boundary'] ?? []);
        $safeToCollapse = (bool) ($boundary['safe_to_collapse'] ?? false);
        if (! $safeToCollapse) {
            return $this->envelope(
                self::STRATEGY_REJECT,
                'reject: boundary not safe to collapse',
                null,
                null,
                ['boundary_not_safe_to_collapse'],
            );
        }

        // Reject when equivalence is not proven.
        if ($verdict !== 'safe_to_consolidate') {
            return $this->envelope(
                self::STRATEGY_REJECT,
                'reject: equivalence ' . $verdict,
                null,
                null,
                ['equivalence_not_proven:'.$verdict]
            );
        }

        // Filter to behavior-preserving candidates.
        $safe = array_values(array_filter(
            $candidates,
            fn ($c) => ($c['preserves_behavior'] ?? false) === true
        ));

        if ($safe === []) {
            return $this->envelope(
                self::STRATEGY_REJECT,
                'reject: no behavior-preserving candidates',
                null,
                null,
                ['no_safe_candidates']
            );
        }

        // Prefer deletion over adapter when both preserve behavior.
        // Among same-strategy candidates, pick the smallest diff.
        usort($safe, function (array $a, array $b): int {
            $sa = (string) ($a['strategy'] ?? '');
            $sb = (string) ($b['strategy'] ?? '');
            // delete < adapter (deletion preferred)
            $stratOrder = [self::STRATEGY_DELETE => 0, self::STRATEGY_ADAPTER => 1];
            $oa = $stratOrder[$sa] ?? 99;
            $ob = $stratOrder[$sb] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return (int) ($a['diff_size'] ?? PHP_INT_MAX) <=> (int) ($b['diff_size'] ?? PHP_INT_MAX);
        });

        $best = $safe[0];
        $strategy = (string) ($best['strategy'] ?? self::STRATEGY_ADAPTER);
        $diffSize = (int) ($best['diff_size'] ?? 0);

        // Runtime dispatch collapses require rollback_gate + proof_command.
        $rollbackGate = null;
        $proofCommand = null;
        $reasons = [];

        if ($touchesRuntime) {
            $rollbackGate = 'git_revert_on_proof_failure';
            $proofCommand = 'php artisan test --filter=CircuitCollapse';
            $reasons[] = 'runtime_dispatch_requires_rollback_gate';
        }

        $reasons[] = "smallest_behavior_preserving_diff:{$strategy}:{$diffSize}";

        return $this->envelope(
            $strategy,
            "{$strategy}: diff_size={$diffSize}",
            $rollbackGate,
            $proofCommand,
            $reasons
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string,strategy:string,recommended_diff:string,rollback_gate:?string,proof_command:?string,reasons:list<string>}
     */
    private function envelope(string $strategy, string $recommendedDiff, ?string $rollbackGate, ?string $proofCommand, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'strategy' => $strategy,
            'recommended_diff' => $recommendedDiff,
            'rollback_gate' => $rollbackGate,
            'proof_command' => $proofCommand,
            'reasons' => $reasons,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * LOOP-OS · FASE 2 · SLICE 9 — the PROJECTION engine: designer ↔ critic to a CONTENT-FIXPOINT over typed
 * obligations. This is the canon's quality phase ("projeção frontier + crítica independente em loop") made
 * MACHINE-CHECKABLE and ungameable — convergence is a set-theoretic fact about typed obligations, never a
 * panel's mood.
 *
 * The DESIGN role proposes each obligation as a typed tuple `{kind, target_symbol, assertion_ref}` where
 * `assertion_ref` names a CONCRETE check the cert chain can run (a real mutation-operator id, a
 * characterization-test target, a consumer-gate). The CRITIC role (writer ≠ judge) raises material
 * obligations the designer must then address. "New obligation across rounds" = set-difference on NORMALIZED
 * keys (whitespace/case stripped, target fully-qualified, assertion-ref canonical) — so a critic cannot be
 * silenced by rephrasing, and a designer cannot fabricate coverage with a non-existent mutation operator.
 *
 * CONVERGED iff (all three): the normalized obligation set STOPPED GROWING for a round; AND the critic
 * RAISED-then-RESOLVED ≥1 material obligation (a contract that never grew is suspect, not converged); AND
 * ≥1 obligation maps to the BINDING system axis (the projection must actually address the bottleneck).
 * Oscillation (the set never stabilizes within `maxRounds`) ⇒ PARK — a liveness floor, never a livelock.
 *
 * PÉTREO: the engine produces the contract the cert chain enforces; the réu can never edit it (it is added
 * to {@see AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS}).
 *
 * HONEST RESIDUAL (§9, model-bound): genuine CROSS-MODEL independence (designer on gpt-5.5, critic on a
 * DISTINCT GLM/MiniMax provider key) and feeding the resulting verdicts into the Certifier's consensus
 * quorum need distinct AiProviderManager keys; with the loop's single hermes_cli key that independence is a
 * provider-infra ceiling, not an architecture gap. This engine enforces the ROLE separation + the
 * deterministic convergence; the model-role binding is the integrator's wiring.
 */
final class AtlasLoopProjectionEngine
{
    public const SCHEMA_VERSION = 'atlas.loop.projection_engine.v1';

    /** The obligation `kind` enum (§10.6). An out-of-enum kind is rejected (cannot smuggle a fake axis). */
    public const KINDS = [
        'behavior_preserved', 'red_to_green', 'mutation_killed', 'consumer_intact',
        'complexity_reduced', 'contract_upheld', 'perf_bound', 'coverage_added',
    ];

    /** Which system axis each obligation kind addresses — so "covers the binding axis" is machine-decidable. */
    private const KIND_AXES = [
        'behavior_preserved' => ['non_trivial', 'safety'],
        'red_to_green' => ['real_target', 'non_trivial'],
        'mutation_killed' => ['non_trivial', 'safety'],
        'consumer_intact' => ['wired', 'compounding'],
        'complexity_reduced' => ['non_trivial'],
        'contract_upheld' => ['wired', 'real_target'],
        'perf_bound' => ['non_trivial'],
        'coverage_added' => ['non_trivial'],
    ];

    private const DEFAULT_MAX_ROUNDS = 8;

    /**
     * Run the designer ↔ critic loop to a content-fixpoint.
     *
     * @param  callable(int, list<array<string,mixed>>):iterable<array<string,mixed>>  $designer  given (round,
     *         current obligations) ⇒ proposed/revised obligation tuples (the gpt-5.5 DESIGN role).
     * @param  callable(list<array<string,mixed>>):array{add?:iterable<array<string,mixed>>, resolved?:iterable<string>}  $critic
     *         the DISTINCT critic role (writer ≠ judge): raises material obligations + marks keys resolved.
     * @return array{schema_version:string, status:string, rounds:int, binding_axis:string,
     *               binding_axis_covered:bool, critic_engaged:bool, obligations:list<array<string,mixed>>,
     *               reason:?string}
     */
    public function project(string $bindingAxis, callable $designer, callable $critic, int $maxRounds = self::DEFAULT_MAX_ROUNDS): array
    {
        $maxRounds = max(1, $maxRounds);
        $obligations = [];        // key => tuple (with its normalized key)
        $criticAdded = [];        // keys the critic raised (cumulative)
        $resolved = [];           // keys marked resolved (cumulative)
        $rounds = 0;

        while ($rounds < $maxRounds) {
            $rounds++;
            $grew = false;

            // DESIGN — propose/revise. Only well-formed, registry-valid obligations enter the set.
            foreach (($designer)($rounds, array_values($obligations)) as $tuple) {
                if ($this->admit($obligations, $tuple)) {
                    $grew = true;
                }
            }

            // CRITIQUE — raise material obligations (writer ≠ judge) + mark prior ones resolved.
            $review = ($critic)(array_values($obligations));
            foreach ((array) ($review['add'] ?? []) as $tuple) {
                $key = $this->obligationKey($tuple);
                if ($key !== null && ! isset($obligations[$key])) {
                    $this->admit($obligations, $tuple);
                    $criticAdded[$key] = true;
                    $grew = true;
                }
            }
            foreach ((array) ($review['resolved'] ?? []) as $rk) {
                $canon = $this->canonicalKey((string) $rk);
                if ($canon !== null) {
                    $resolved[$canon] = true;
                }
            }

            // CONVERGED — set stable this round AND critic raised-then-resolved ≥1 AND binding axis covered.
            $criticEngaged = $this->intersects($criticAdded, $resolved);
            $covered = $this->bindingAxisCovered($obligations, $bindingAxis);
            if (! $grew && $criticEngaged && $covered) {
                return $this->result('converged', $rounds, $bindingAxis, $covered, $criticEngaged, $obligations, null);
            }
        }

        return $this->result(
            'parked',
            $rounds,
            $bindingAxis,
            $this->bindingAxisCovered($obligations, $bindingAxis),
            $this->intersects($criticAdded, $resolved),
            $obligations,
            'oscillation_no_content_fixpoint',
        );
    }

    /**
     * Normalize an obligation tuple to its rephrase-proof key, or null when malformed/ungrounded. PUBLIC so
     * the slice test can prove the normalization (whitespace/case-insensitive) AND the registry rejection of
     * a fabricated mutation operator directly.
     *
     * @param  array<string,mixed>  $tuple
     */
    public function obligationKey(array $tuple): ?string
    {
        $kind = strtolower(trim((string) ($tuple['kind'] ?? '')));
        if (! in_array($kind, self::KINDS, true)) {
            return null; // out-of-enum kind cannot smuggle a phantom axis
        }
        $target = $this->fqcn((string) ($tuple['target_symbol'] ?? ''));
        if ($target === '') {
            return null;
        }
        $ref = $this->canonicalAssertionRef((string) ($tuple['assertion_ref'] ?? ''));
        if ($ref === null) {
            return null; // ungrounded / fabricated assertion_ref ⇒ not a real check ⇒ rejected
        }

        return $kind.'|'.$target.'|'.$ref;
    }

    /**
     * @param  array<string,array<string,mixed>>  $obligations  (by-ref accumulator)
     * @param  array<string,mixed>  $tuple
     */
    private function admit(array &$obligations, array $tuple): bool
    {
        $key = $this->obligationKey($tuple);
        if ($key === null || isset($obligations[$key])) {
            return false;
        }
        $obligations[$key] = ['key' => $key, 'kind' => strtolower(trim((string) $tuple['kind'])), 'target_symbol' => $this->fqcn((string) $tuple['target_symbol']), 'assertion_ref' => $this->canonicalAssertionRef((string) $tuple['assertion_ref'])];

        return true;
    }

    /** Fully-qualified, whitespace/case-stripped target — so `\App\Foo :: bar` and `app\foo::bar` collide. */
    private function fqcn(string $symbol): string
    {
        return strtolower(ltrim(preg_replace('/\s+/', '', $symbol) ?? '', '\\'));
    }

    /**
     * Canonicalize an assertion_ref, or null when it does not name a CONCRETE, EXISTING check:
     *  - `mutop:<id>`     — <id> must be a REAL operator in {@see AtlasLoopMutationOperators::map()}.
     *  - `chartest:<p::m>`— a characterization-test target path::method.
     *  - `consumer:<fqcn>`— a cross-file consumer gate target.
     */
    private function canonicalAssertionRef(string $ref): ?string
    {
        $ref = strtolower(preg_replace('/\s+/', '', $ref) ?? '');
        if (str_starts_with($ref, 'mutop:')) {
            $id = substr($ref, 6);

            return ($id !== '' && array_key_exists($id, AtlasLoopMutationOperators::map())) ? $ref : null;
        }
        if (str_starts_with($ref, 'chartest:')) {
            $body = substr($ref, 9);

            return str_contains($body, '::') ? $ref : null;
        }
        if (str_starts_with($ref, 'consumer:')) {
            return substr($ref, 9) !== '' ? $ref : null;
        }

        return null;
    }

    private function canonicalKey(string $key): ?string
    {
        $canon = strtolower(preg_replace('/\s+/', '', $key) ?? '');

        return $canon === '' ? null : $canon;
    }

    /**
     * @param  array<string,array<string,mixed>>  $obligations
     */
    private function bindingAxisCovered(array $obligations, string $bindingAxis): bool
    {
        $bindingAxis = strtolower(trim($bindingAxis));
        foreach ($obligations as $o) {
            $axes = self::KIND_AXES[(string) $o['kind']] ?? [];
            if (in_array($bindingAxis, $axes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,bool>  $a
     * @param  array<string,bool>  $b
     */
    private function intersects(array $a, array $b): bool
    {
        foreach (array_keys($a) as $k) {
            if (isset($b[$k])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,array<string,mixed>>  $obligations
     * @return array<string,mixed>
     */
    private function result(string $status, int $rounds, string $bindingAxis, bool $covered, bool $criticEngaged, array $obligations, ?string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'rounds' => $rounds,
            'binding_axis' => $bindingAxis,
            'binding_axis_covered' => $covered,
            'critic_engaged' => $criticEngaged,
            'obligations' => array_values($obligations),
            'reason' => $reason,
        ];
    }
}

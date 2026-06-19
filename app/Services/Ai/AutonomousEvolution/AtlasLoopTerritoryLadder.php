<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * SLICE C-territory-ladder — the ARMED, NOT-promoted territory ladder.
 *
 * The loop's scope is released GRADUALLY, gated on PROVEN quality. Widening a discovery root is the most
 * dangerous act the loop can perform: a new territory carries its OWN judge (the safety files that decide
 * whether the loop is allowed to edit there). If the loop widens a root WITHOUT freezing that territory's
 * safety files, it could then edit the very judge that guards the new territory — the no-blinder constitution
 * collapses silently.
 *
 * This class ENFORCES the atomic three-set promotion invariant. It is the ARMED MECHANISM + THE GATE; it does
 * NOT actually widen any live discovery root. A promotion is atomic over THREE sets — DISCOVERY_ROOTS,
 * FROZEN safety files, and the robustness corpus — and must satisfy the load-bearing invariant:
 *
 *     DISCOVERY_ROOTS ⊇ territory  ⟹  territory safety files ∈ FROZEN  ∧  ≥1 robustness case
 *
 * i.e. every discovery root being widened MUST have at least one frozen safety file living under it, and the
 * territory must ship at least one robustness case. Only when the invariant holds AND the promotion rule is
 * met (enough certified leaps, zero red-main in the window, compounding trending up) is the territory
 * promotable. Pure + deterministic — no provider, no DB.
 */
final class AtlasLoopTerritoryLadder
{
    /** Default K: certified leaps required before a territory may be promoted. */
    public const DEFAULT_CERTIFIED_LEAPS_THRESHOLD = 3;

    /**
     * @param array{
     *     name?:string,
     *     discovery_roots?:list<string>,
     *     frozen_safety_files?:list<string>,
     *     robustness_cases?:int,
     *     certified_leaps?:int,
     *     red_main_in_window?:int,
     *     compounding_trend_up?:bool
     * } $territory
     * @return array{promotable:bool, invariant_holds:bool, promotion_rule_met:bool, violations:list<string>}
     */
    public function canPromote(array $territory, int $certifiedLeapsThreshold = self::DEFAULT_CERTIFIED_LEAPS_THRESHOLD): array
    {
        $k = max(1, $certifiedLeapsThreshold);

        $name = (string) ($territory['name'] ?? '');
        $roots = $this->normalizeList($territory['discovery_roots'] ?? []);
        $frozen = $this->normalizeList($territory['frozen_safety_files'] ?? []);
        $robustnessCases = max(0, (int) ($territory['robustness_cases'] ?? 0));
        $certifiedLeaps = max(0, (int) ($territory['certified_leaps'] ?? 0));
        $redMainInWindow = max(0, (int) ($territory['red_main_in_window'] ?? 0));
        $compoundingTrendUp = (bool) ($territory['compounding_trend_up'] ?? false);

        $violations = [];

        // --- INVARIANT, part 1: every widened root must have a frozen safety file UNDER it. ---
        // A widened root whose safety files are NOT frozen is REJECTED: the loop could edit the new
        // territory's judge. This is the load-bearing safety check.
        if ($roots === []) {
            $violations[] = 'no_discovery_root:'.($name !== '' ? $name : 'territory');
        }
        foreach ($roots as $root) {
            if (! $this->hasFrozenSafetyFileUnder($root, $frozen)) {
                $violations[] = 'unprotected_root:'.$root;
            }
        }

        // --- INVARIANT, part 2: at least one robustness case. ---
        if ($robustnessCases < 1) {
            $violations[] = 'no_robustness_case';
        }

        $invariantHolds = $violations === [];

        // --- PROMOTION RULE: certified_leaps >= K, zero red-main in window, compounding trending up. ---
        $promotionViolations = [];
        if ($certifiedLeaps < $k) {
            $promotionViolations[] = 'insufficient_certified_leaps:'.$certifiedLeaps.'/'.$k;
        }
        if ($redMainInWindow !== 0) {
            $promotionViolations[] = 'red_main_in_window:'.$redMainInWindow;
        }
        if ($compoundingTrendUp !== true) {
            $promotionViolations[] = 'compounding_trend_not_up';
        }
        $promotionRuleMet = $promotionViolations === [];

        foreach ($promotionViolations as $v) {
            $violations[] = $v;
        }

        // PROMOTABLE only when BOTH the safety invariant holds AND the promotion rule is met.
        $promotable = $invariantHolds && $promotionRuleMet;

        return [
            'promotable' => $promotable,
            'invariant_holds' => $invariantHolds,
            'promotion_rule_met' => $promotionRuleMet,
            'violations' => array_values($violations),
        ];
    }

    /**
     * A frozen safety file protects a root iff the file path lives UNDER that root (or equals it). Paths are
     * normalized to forward-slash form; a trailing slash is appended to the root so 'app/Foo' does not match
     * 'app/FooBar' (prefix-safety).
     *
     * @param list<string> $frozen
     */
    private function hasFrozenSafetyFileUnder(string $root, array $frozen): bool
    {
        $root = $this->normalizePath($root);
        if ($root === '') {
            return false;
        }
        $rootPrefix = rtrim($root, '/').'/';

        foreach ($frozen as $file) {
            $file = $this->normalizePath($file);
            if ($file === '') {
                continue;
            }
            if ($file === $root || str_starts_with($file, $rootPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function normalizeList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $item = $this->normalizePath($item);
                if ($item !== '') {
                    $out[] = $item;
                }
            }
        }

        return array_values($out);
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }
}

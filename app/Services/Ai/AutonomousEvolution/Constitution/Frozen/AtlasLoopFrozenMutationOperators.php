<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution\Frozen;

/**
 * LOOP-OS · FASE 3 · SLICE 3 — the FROZEN mutation-operator snapshot (pétreo / FORBIDDEN, under Constitution/).
 *
 * The battery's "a candidate judge must still kill every radius-1 mutant" guarantee can only be trusted if
 * the KILL VOCABULARY it generates neighbours from cannot itself be shrunk. The live {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators}
 * is slated to become `property_gated` (Slice 4) — so a candidate could submit an edit WEAKENING a transform
 * (narrow a regex, make a comment `>` survivable) and, if this snapshot DELEGATED to it, the weakened
 * transform would flow into {@see neighborhood()} and silently shrink the must-catch region. To close that
 * transitive-unfreeze blinder hole, this class is a SELF-CONTAINED VERBATIM COPY of the live class's
 * always-on operators + helpers — it references the live class at runtime NOWHERE. "the loop cannot saw the
 * thing that judges the judge."
 *
 * The two copies are kept honest by a RE-ATTESTATION TRIPWIRE test (frozen ids === live base-off ids), which
 * goes RED if the live moat changes without a deliberate re-freeze here. This snapshot does NOT by itself
 * discharge the no-blinder guarantee — it is INERT data + a frozen reader; the candidate-bytes execution that
 * proves a judge still kills these mutants is the Slice-4.5 BatteryRunner (a clone-local subprocess).
 *
 * Verbatim-copied from AtlasLoopMutationOperators @ 2026-06-18 (always-on map; the 3 config-gated extras are
 * DELIBERATELY excluded — the Constitution must not depend on an off-by-default flag for its must-catch set).
 */
final class AtlasLoopFrozenMutationOperators
{
    /** The frozen core kill-vocabulary (the always-on operator ids). Growing it strictly strengthens. */
    public const FROZEN_OPERATOR_IDS = [
        'return_string_literal',
        'return_true',
        'return_false',
        'strict_equals',
        'strict_not_equals',
        'return_integer',
        'positive_comparison',
        'gte_comparison',
        'lte_comparison',
        'loose_equals',
        'loose_not_equals',
        'gt_comparison',
        'lt_comparison',
        'job_dispatch_noop',
        'db_insert_noop',
        'string_literal',
    ];

    /**
     * The FROZEN transform map — a verbatim copy of the live always-on operators. Self-contained: it calls
     * only this class's own helpers, never the (soon-property_gated) live class.
     *
     * @return array<string, callable(string):?string>
     */
    public static function map(): array
    {
        return [
            'return_string_literal' => static fn (string $source): ?string => self::replaceFirst('/return\s+([\'"])(?:\\\\.|(?!\1).)*\1\s*;/', "return '__atlas_mutant__';", $source),
            'return_true' => static fn (string $source): ?string => self::replaceFirst('/return\s+true\s*;/', 'return false;', $source),
            'return_false' => static fn (string $source): ?string => self::replaceFirst('/return\s+false\s*;/', 'return true;', $source),
            'strict_equals' => static fn (string $source): ?string => self::replaceFirst('/===/', '!==', $source),
            'strict_not_equals' => static fn (string $source): ?string => self::replaceFirst('/!==/', '===', $source),
            'return_integer' => static fn (string $source): ?string => self::replaceFirstCallback('/return\s+(-?\d+)\s*;/', static fn (array $m): string => 'return '.(((int) $m[1]) === 0 ? '1' : '0').';', $source),
            'positive_comparison' => static fn (string $source): ?string => self::replaceFirst('/>\s*0/', '<= 0', $source),
            'gte_comparison' => static fn (string $source): ?string => self::relationalReplace('/>=/', '<', $source),
            'lte_comparison' => static fn (string $source): ?string => self::relationalReplace('/<=/', '>', $source),
            'loose_equals' => static fn (string $source): ?string => self::relationalReplace('/(?<![=!<>])==(?![=])/', '!=', $source),
            'loose_not_equals' => static fn (string $source): ?string => self::relationalReplace('/!=(?![=])/', '==', $source),
            'gt_comparison' => static fn (string $source): ?string => self::relationalReplace('/(?<![=<>-])>(?![=>])/', '<=', $source),
            'lt_comparison' => static fn (string $source): ?string => self::relationalReplace('/(?<![=<>])<(?![=<])/', '>=', $source),
            'job_dispatch_noop' => static fn (string $source): ?string => self::replaceFirst('/\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::dispatch\(\);/', ';', $source),
            'db_insert_noop' => static fn (string $source): ?string => self::replaceFirst('/->insert\(/', "->whereRaw('1 = 0')->update(", $source),
            'string_literal' => static fn (string $source): ?string => self::replaceFirst('/([\'"])(?:\\\\.|(?!\1).){1,160}\1/', "'__atlas_mutant__'", $source),
        ];
    }

    /** @return list<string> the frozen operator ids (the immutable kill vocabulary) */
    public static function ids(): array
    {
        return self::FROZEN_OPERATOR_IDS;
    }

    /**
     * Apply a FROZEN operator by id, via this class's OWN frozen transform map. Returns the mutated source,
     * or null when the id is not frozen or the operator does not apply.
     */
    public static function apply(string $operator, string $source): ?string
    {
        $map = self::map();

        return isset($map[$operator]) ? $map[$operator]($source) : null;
    }

    /**
     * The radius-1 mutation neighbourhood of a source: every frozen operator applied once that actually
     * changes the source — the mutants a candidate judge MUST still REFUTE.
     *
     * @return array<string,string>  operator id => mutated source
     */
    public static function neighborhood(string $source): array
    {
        $out = [];
        foreach (array_keys(self::map()) as $id) {
            $mutated = self::apply($id, $source);
            if (is_string($mutated) && $mutated !== $source) {
                $out[$id] = $mutated;
            }
        }

        return $out;
    }

    // ── Helpers — verbatim copies of AtlasLoopMutationOperators's (no shared base; the tripwire keeps lockstep) ──

    private static function replaceFirst(string $pattern, string $replacement, string $source): ?string
    {
        $count = 0;
        $mutated = preg_replace($pattern, $replacement, $source, 1, $count);

        return $count > 0 && is_string($mutated) ? $mutated : null;
    }

    /** @param callable(array<int,string>):string $callback */
    private static function replaceFirstCallback(string $pattern, callable $callback, string $source): ?string
    {
        $count = 0;
        $mutated = preg_replace_callback($pattern, $callback, $source, 1, $count);

        return $count > 0 && is_string($mutated) ? $mutated : null;
    }

    private static function relationalReplace(string $pattern, string $replacement, string $source): ?string
    {
        $masked = self::maskCommentsAndStrings($source);
        if (preg_match($pattern, $masked, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $offset = (int) $m[0][1];
        $length = strlen((string) $m[0][0]);
        $mutated = substr_replace($source, $replacement, $offset, $length);

        return $mutated !== $source ? $mutated : null;
    }

    private static function maskCommentsAndStrings(string $source): string
    {
        $len = strlen($source);
        $out = $source;
        $i = 0;
        while ($i < $len) {
            $c = $source[$i];
            $next = $i + 1 < $len ? $source[$i + 1] : '';
            if (($c === '/' && $next === '/') || $c === '#') {
                while ($i < $len && $source[$i] !== "\n") {
                    $out[$i] = ' ';
                    $i++;
                }

                continue;
            }
            if ($c === '/' && $next === '*') {
                while ($i < $len) {
                    if ($source[$i] === "\n") {
                        $i++;

                        continue;
                    }
                    $closing = $source[$i] === '*' && ($i + 1 < $len) && $source[$i + 1] === '/';
                    $out[$i] = ' ';
                    $i++;
                    if ($closing) {
                        if ($i < $len) {
                            $out[$i] = ' ';
                            $i++;
                        }

                        break;
                    }
                }

                continue;
            }
            if ($c === '\'' || $c === '"') {
                $quote = $c;
                $out[$i] = ' ';
                $i++;
                while ($i < $len) {
                    if ($source[$i] === '\\' && $i + 1 < $len) {
                        $out[$i] = ' ';
                        $out[$i + 1] = $source[$i + 1] === "\n" ? "\n" : ' ';
                        $i += 2;

                        continue;
                    }
                    $isClose = $source[$i] === $quote;
                    if ($source[$i] !== "\n") {
                        $out[$i] = ' ';
                    }
                    $i++;
                    if ($isClose) {
                        break;
                    }
                }

                continue;
            }
            $i++;
        }

        return $out;
    }
}

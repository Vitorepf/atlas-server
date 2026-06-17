<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Single source of truth for the loop's source-level mutation operators. Extracted verbatim from
 * AtlasLoopMutationAdequacyGateService so the mutation-adequacy gate (which SAMPLES a mutant to test
 * coverage) and the characterization-test verifier (which RE-APPLIES a specific operator to prove a
 * new test KILLS it) share ONE definition — a second copy would let the gate and the verifier
 * disagree on what a given operator does, silently certifying useless tests.
 *
 * Pure + deterministic: every transform returns the mutated source or null when the operator does not
 * apply. Relational/equality operators are matched only in real CODE (comments/strings masked) so a
 * no-op edit to a docblock `>` can never masquerade as a behaviour mutant.
 */
final class AtlasLoopMutationOperators
{
    /**
     * Cosmetic operators: flipping these does NOT change behaviour. A surviving cosmetic mutant must
     * not decide a behaviour-preserving refactor's fate (decision-aware sampling skips them).
     */
    public const COSMETIC_OPERATORS = [
        'return_string_literal' => true,
        'string_literal' => true,
    ];

    /**
     * Operator => transform. Ordered longest-first for relational operators so a shorter operator
     * never eats a longer one. First-applicable wins when the gate samples without a preference.
     *
     * @return array<string, callable(string):?string>
     */
    public static function map(): array
    {
        $map = [
            'return_string_literal' => static fn (string $source): ?string => self::replaceFirst('/return\s+([\'"])(?:\\\\.|(?!\1).)*\1\s*;/', "return '__atlas_mutant__';", $source),
            'return_true' => static fn (string $source): ?string => self::replaceFirst('/return\s+true\s*;/', 'return false;', $source),
            'return_false' => static fn (string $source): ?string => self::replaceFirst('/return\s+false\s*;/', 'return true;', $source),
            'strict_equals' => static fn (string $source): ?string => self::replaceFirst('/===/', '!==', $source),
            'strict_not_equals' => static fn (string $source): ?string => self::replaceFirst('/!==/', '===', $source),
            'return_integer' => static fn (string $source): ?string => self::replaceFirstCallback('/return\s+(-?\d+)\s*;/', static fn (array $m): string => 'return '.(((int) $m[1]) === 0 ? '1' : '0').';', $source),
            'positive_comparison' => static fn (string $source): ?string => self::replaceFirst('/>\s*0/', '<= 0', $source),
            // Fix B (adversarial panel, 2026-06-14): BROADEN the decision vocabulary to ALL relational/
            // equality operators generically, each a real behaviour-changing mutant. Ordered longest-first
            // so a shorter operator never eats a longer one. >=,<=,<,> (any RHS) cover the common
            // refactor decisions that the >0/=== narrow set used to miss (false reject -> certs=0).
            // Each routes through {@see relationalReplace} which IGNORES operators inside comments/
            // docblocks (e.g. the `>` in `@var array<int,string>`) so a no-op comment edit can never
            // masquerade as a decision mutant — that would survive and FALSELY reject a real refactor.
            'gte_comparison' => static fn (string $source): ?string => self::relationalReplace('/>=/', '<', $source),
            'lte_comparison' => static fn (string $source): ?string => self::relationalReplace('/<=/', '>', $source),
            'loose_equals' => static fn (string $source): ?string => self::relationalReplace('/(?<![=!<>])==(?![=])/', '!=', $source),
            'loose_not_equals' => static fn (string $source): ?string => self::relationalReplace('/!=(?![=])/', '==', $source),
            // > not part of >=, =>, ->, >>, or the >0 already handled above; < not part of <=, <<, or </> tags.
            'gt_comparison' => static fn (string $source): ?string => self::relationalReplace('/(?<![=<>-])>(?![=>])/', '<=', $source),
            'lt_comparison' => static fn (string $source): ?string => self::relationalReplace('/(?<![=<>])<(?![=<])/', '>=', $source),
            'job_dispatch_noop' => static fn (string $source): ?string => self::replaceFirst('/\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::dispatch\(\);/', ';', $source),
            'db_insert_noop' => static fn (string $source): ?string => self::replaceFirst('/->insert\(/', "->whereRaw('1 = 0')->update(", $source),
            'string_literal' => static fn (string $source): ?string => self::replaceFirst('/([\'"])(?:\\\\.|(?!\1).){1,160}\1/', "'__atlas_mutant__'", $source),
        ];

        // ACDE QA1 — three extra deterministic DECISION operators that widen the kill vocabulary toward 9.3:
        // remove a thrown exception, force a `??` fallback to null, delete a void early-return. Non-cosmetic =>
        // the gate auto-treats each as a decision and joins them to the exhaustive hunt; single-sourced here so
        // the gate and the characterization verifier (applyOperator) agree. Read defensively (a pure-unit caller
        // never fatals). Default OFF => map is identical => byte-identical.
        $extra = false;
        try {
            $extra = (bool) config('atlas.loop.extra_mutation_operators_enabled', false);
        } catch (\Throwable) {
            $extra = false;
        }
        if ($extra) {
            $map['exception_throw_noop'] = static fn (string $source): ?string => self::replaceFirst('/throw\s+new\b[^;]*;/', ';', $source);
            $map['null_coalesce_null'] = static fn (string $source): ?string => self::replaceFirst('/\?\?\s*[^;()\[\]{}?:,]+/', '?? null', $source);
            $map['early_return_delete'] = static fn (string $source): ?string => self::replaceFirst('/(?<![\w$>=])return\s*;/', ';', $source);
        }

        return $map;
    }

    /**
     * Apply a SPECIFIC operator's transform to the source. Returns the mutated source, or null when
     * the operator is unknown or does not apply. This is the verifier's entry point: re-apply the
     * exact operator the gate sampled and confirm a new test now fails on it.
     */
    public static function applyOperator(string $operator, string $source): ?string
    {
        $map = self::map();
        if (! isset($map[$operator])) {
            return null;
        }

        return $map[$operator]($source);
    }

    public static function isCosmetic(string $operator): bool
    {
        return isset(self::COSMETIC_OPERATORS[$operator]);
    }

    /**
     * Replace the FIRST relational/equality operator that lives in real CODE — never one inside a
     * comment, docblock or string literal. Comment/string spans are masked to spaces before locating
     * the operator, then the replacement is applied at that exact offset in the ORIGINAL source so
     * surrounding code/strings stay byte-intact.
     */
    public static function relationalReplace(string $pattern, string $replacement, string $source): ?string
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

    /**
     * Replace every byte inside a // , # , block or doc comment or a single/double quoted string with a
     * space, preserving length and newlines so offsets in the masked string map 1:1 onto the original.
     */
    public static function maskCommentsAndStrings(string $source): string
    {
        $len = strlen($source);
        $out = $source;
        $i = 0;
        while ($i < $len) {
            $c = $source[$i];
            $next = $i + 1 < $len ? $source[$i + 1] : '';
            // Line comment: // or #
            if (($c === '/' && $next === '/') || $c === '#') {
                while ($i < $len && $source[$i] !== "\n") {
                    $out[$i] = ' ';
                    $i++;
                }

                continue;
            }
            // Block / doc comment: /* ... */
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
            // String literal: ' ... ' or " ... " (respecting backslash escapes)
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

    public static function replaceFirst(string $pattern, string $replacement, string $source): ?string
    {
        $count = 0;
        $mutated = preg_replace($pattern, $replacement, $source, 1, $count);

        return $count > 0 && is_string($mutated) ? $mutated : null;
    }

    /**
     * @param  callable(array<int,string>):string  $callback
     */
    public static function replaceFirstCallback(string $pattern, callable $callback, string $source): ?string
    {
        $count = 0;
        $mutated = preg_replace_callback($pattern, $callback, $source, 1, $count);

        return $count > 0 && is_string($mutated) ? $mutated : null;
    }
}

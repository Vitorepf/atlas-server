<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §5.6 · DEDUP — the order-preserving, literal+identifier-AWARE clone normalizer.
 *
 * Clone-unification must NEVER unify two method bodies that are not truly behavior-equivalent. The two
 * existing public normalizers both fail that bar:
 *   - {@see AtlasLoopCloneDetector::similarity} is multiset Jaccard — ORDER-INSENSITIVE: `a(); b();` scores
 *     1.0 against `b(); a();` (the "dedup that does not preserve behavior" worst-failure).
 *   - {@see \App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer::normalizePhpForClone} is
 *     order-preserving but literal-LOSSY: it collapses literals to `lit` and identifiers to `id`, so
 *     `config('atlas.loop.x')` == `config('atlas.loop.y')` — a silent wrong-config-key unification.
 *
 * This normalizer is order-preserving (a real statement stream) AND keeps literals + identifiers verbatim, so
 * a literal or call-target divergence makes two bodies NON-equivalent. It collapses ONLY variable NAMES (a pure
 * rename is behavior-preserving: `$a` ≡ `$total`). Two bodies are admissible clones iff their normalizations are
 * IDENTICAL. Pure + deterministic (token-level), so it can run inside the frozen judge's dedup proof.
 */
final class AtlasLoopCloneOrderedNormalizer
{
    /**
     * The canonical token stream: whitespace/comments dropped, variable names collapsed to `$v`, EVERYTHING
     * else (literals, identifiers, operators, keywords) kept verbatim and IN ORDER.
     */
    public function normalize(string $code): string
    {
        $tokens = @token_get_all("<?php\n".$code);
        $out = [];
        foreach ($tokens as $token) {
            if (is_string($token)) {
                $out[] = $token;

                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue; // structural noise — never behavioral
            }
            if ($id === T_VARIABLE) {
                $out[] = '$v'; // a pure variable RENAME is behavior-preserving — the only thing we abstract.
                continue;
            }
            // Literals (T_CONSTANT_ENCAPSED_STRING/T_LNUMBER/T_DNUMBER) and identifiers (T_STRING) are KEPT
            // verbatim: a `config('x')` vs `config('y')`, or `return 1` vs `return 2`, divergence is BEHAVIORAL
            // and must make the bodies non-equivalent (never silently unified).
            $out[] = $text;
        }

        return implode('', $out);
    }

    /** Stable hash of the normalized stream (for clone-cluster membership counting in the dedup proof). */
    public function hash(string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }

    /** Two bodies are admissible clones iff their normalizations are byte-identical. */
    public function equivalent(string $a, string $b): bool
    {
        return $this->normalize($a) === $this->normalize($b);
    }
}

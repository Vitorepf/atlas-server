<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §11.6 — cross-file clone detection (dedup is a canon core value, loop-canonical-definition).
 *
 * Detects structural duplication ACROSS files so the loop can propose a real dedupe (extract the shared
 * logic) rather than letting copy-paste rot accumulate. The comparison is name- and whitespace-INSENSITIVE:
 * the PHP source is tokenized with the native tokenizer, then whitespace and comments are dropped and every
 * VARIABLE name is rewritten to a single placeholder ($V). So `$a + $b` and `$total + $delta` collapse to the
 * same token stream — a renamed copy of a function still reads as a clone. Two token MULTISETS are then
 * compared with a multiset Jaccard (|A ∩ B| / |A ∪ B|), counting repeats so a 10-line block copied twice in
 * one file weighs more than a single shared keyword.
 *
 * Pure + deterministic: no clock, DB, filesystem or provider — the same sources always yield the same
 * similarity, so a clone pair is reproducible and the dedupe objective is stable.
 */
final class AtlasLoopCloneDetector
{
    /** The single placeholder every variable name normalizes to, so renamed clones still match. */
    private const VAR_PLACEHOLDER = '$V';

    /**
     * Normalized-token similarity of two PHP sources, in [0,1].
     *
     * 1.0 = identical structure modulo variable names and whitespace/comments; 0.0 = disjoint token
     * multisets. Computed as multiset Jaccard over the normalized token streams. Two empty/structure-only
     * streams are defined as 1.0 (vacuously identical); one empty vs non-empty is 0.0.
     */
    public function similarity(string $sourceA, string $sourceB): float
    {
        $a = $this->normalizedTokenCounts($sourceA);
        $b = $this->normalizedTokenCounts($sourceB);

        return $this->multisetJaccard($a, $b);
    }

    /**
     * All cross-file clone pairs whose similarity >= $threshold.
     *
     * @param  array<string, string>  $filesByPath  path => PHP source
     * @return list<array{a: string, b: string, similarity: float}>  each pair has a < b (string order), no
     *                                                                self-pair, no duplicate pair; sorted by
     *                                                                similarity desc then (a,b) for determinism.
     */
    public function detectClones(array $filesByPath, float $threshold = 0.85): array
    {
        // Pre-tokenize once per file (O(n) tokenizations, not O(n²)).
        $paths = array_keys($filesByPath);
        sort($paths, SORT_STRING);

        $counts = [];
        foreach ($paths as $path) {
            $counts[$path] = $this->normalizedTokenCounts($filesByPath[$path]);
        }

        $pairs = [];
        $n = count($paths);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $paths[$i];
                $b = $paths[$j];
                $sim = $this->multisetJaccard($counts[$a], $counts[$b]);
                if ($sim >= $threshold) {
                    $pairs[] = ['a' => $a, 'b' => $b, 'similarity' => $sim];
                }
            }
        }

        usort($pairs, static function (array $x, array $y): int {
            return [$y['similarity'], $x['a'], $x['b']] <=> [$x['similarity'], $y['a'], $y['b']];
        });

        return $pairs;
    }

    /**
     * The dedupe task spec for a detected clone pair: extract the shared logic, target the FIRST file.
     *
     * @param  array{a: string, b: string, similarity?: float}  $clonePair
     * @return array{objective: string, target_path: string, shape: string}
     */
    public function dedupObjective(array $clonePair): array
    {
        $a = (string) $clonePair['a'];
        $b = (string) $clonePair['b'];

        return [
            'objective' => "Extract the shared logic duplicated between {$a} and {$b} into a single "
                ."reusable unit and have both call sites delegate to it (dedupe the clone). Preserve "
                ."behavior exactly; the shared extraction must be the only home for the logic.",
            'target_path' => $a,
            'shape' => 'dedup',
        ];
    }

    /**
     * Multiset Jaccard: sum(min) / sum(max) over the union of token kinds. Counts repeats so structurally
     * heavier overlaps score higher. Two empty multisets ⇒ 1.0; one empty ⇒ 0.0.
     *
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     */
    private function multisetJaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }

        $intersection = 0;
        $union = 0;
        foreach (array_keys($a + $b) as $token) {
            $ca = $a[$token] ?? 0;
            $cb = $b[$token] ?? 0;
            $intersection += min($ca, $cb);
            $union += max($ca, $cb);
        }

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Normalize PHP source into a token-kind multiset.
     *
     * - Whitespace (T_WHITESPACE) and comments (T_COMMENT, T_DOC_COMMENT) are dropped.
     * - The opening tag (T_OPEN_TAG) is dropped so a leading `<?php` does not anchor every file together.
     * - Every variable (T_VARIABLE) collapses to a single placeholder, so `$a` and `$total` are the same
     *   token — renamed clones still match.
     * - All other tokens keep their structure: keywords/operators by name, named tokens by "<kind>:<text>"
     *   so identifiers and literals still distinguish genuinely different logic.
     *
     * @return array<string, int>  token-string => occurrence count
     */
    private function normalizedTokenCounts(string $source): array
    {
        $tokens = $this->safeTokenize($source);
        $counts = [];

        foreach ($tokens as $token) {
            $norm = $this->normalizeToken($token);
            if ($norm === null) {
                continue;
            }
            $counts[$norm] = ($counts[$norm] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Normalize one token to a canonical string, or null to drop it.
     *
     * @param  array{0: int, 1: string, 2: int}|string  $token  a token_get_all entry
     */
    private function normalizeToken(array|string $token): ?string
    {
        // Single-char tokens (operators/punctuation like ; { } ( ) + . , = ) are plain strings.
        if (is_string($token)) {
            return 'op:'.$token;
        }

        $id = $token[0];
        $text = $token[1];

        // Structural noise — drop entirely so it can't anchor similarity.
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT
            || $id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO || $id === T_CLOSE_TAG) {
            return null;
        }

        // Variable names → single placeholder so renamed clones collapse together.
        if ($id === T_VARIABLE) {
            return self::VAR_PLACEHOLDER;
        }

        // Everything else keeps its lexeme so different identifiers/literals stay distinct.
        return token_name($id).':'.$text;
    }

    /**
     * Tokenize defensively. token_get_all can emit warnings on malformed input; we suppress and fall back to
     * an empty stream so a broken file simply scores as no-clone instead of throwing inside discovery.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function safeTokenize(string $source): array
    {
        // Ensure an opening tag so the tokenizer treats the body as PHP (raw snippets without <?php would
        // otherwise be a single T_INLINE_HTML blob). The injected tag is dropped in normalization anyway.
        if (! str_contains($source, '<?')) {
            $source = "<?php\n".$source;
        }

        $previous = set_error_handler(static function (): bool {
            return true;
        });

        try {
            $tokens = token_get_all($source);
        } catch (\Throwable) {
            $tokens = [];
        } finally {
            restore_error_handler();
            // restore_error_handler pops our handler; $previous is reinstated implicitly by the pop.
            unset($previous);
        }

        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        return $tokens;
    }
}

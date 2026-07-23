<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;


/**
 * E4 -- Extracts PHP function/method definitions from source text.
 *
 * A deterministic, regex-and-brace-counter extractor that finds top-level
 * `function` declarations (free functions and class methods) in a PHP
 * source file and returns each as a {@see ExtractedSymbol} carrying:
 *   - the symbol name (function/method name, not the class FQN);
 *   - a stable signature string (the parameter list, used as the comparison
 *     key between old and new — a function whose signature changed is still
 *     a candidate, the BODY is what diverges);
 *   - the full body source (interior between the matching braces).
 *
 * The extractor is INTENTIONALLY lightweight: it does NOT use token_get_all
 * + a PHP parser (that would pull a heavy dependency and over-fit to PHP
 * grammar edge cases). It uses a brace-depth counter from the signature's
 * opening `{` to its matching `}`, which is robust for the well-formed PHP
 * the Atlas Dev pipeline produces. Strings/comments containing braces are
 * handled by a simple escaper that blanks them before counting.
 *
 * Extracted symbols are the input to {@see ShadowDiffService}, which:
 *   - diffs the OLD vs NEW symbol sets (a symbol in BOTH is a MODIFIED
 *     candidate; a symbol ONLY in NEW is newly-added => skip, VAL-E4-011;
 *     a symbol ONLY in OLD was deleted, not a shadow-diff concern);
 *   - extracts old + new body source for each MODIFIED symbol;
 *   - runs {@see PureFunctionDetector} on both bodies (conservative);
 *   - hands both bodies + probe inputs to the {@see ShadowDiffHarness}.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffSymbolExtractor
{
    /**
     * Extract every function/method definition found in $source.
     *
     * Returns a map of symbol-name => ExtractedSymbol. When the same name
     * appears twice (e.g. a method redeclared in the same file, which is a
     * fatal in PHP anyway), the LAST definition wins.
     *
     * @return array<non-empty-string, ExtractedSymbol>
     */
    public function extract(string $source): array
    {
        if ($source === '') {
            return [];
        }

        // Blank out string literals and comments so braces inside them do
        // not fool the depth counter. This is a conservative escaper: it
        // may over-blank, but it never under-blanks (which would break
        // brace counting). The body returned to the caller is the ORIGINAL
        // source, not the blanked version.
        $blanked = $this->blankStringsAndComments($source);

        // Match function declarations. The pattern is deliberately generous:
        // it accepts optional modifiers (public/protected/private/static/
        // final/abstract) before `function`, then the name, then the
        // parameter list, then the return type (optional), then `{`.
        // It does NOT match anonymous closures (`function(` with no name)
        // or arrow functions (`fn(`) because those have no stable symbol
        // identity to compare across versions.
        $pattern = '/
            (?:public|protected|private|static|final|abstract|\s)*
            function\s+
            ([A-Za-z_][A-Za-z0-9_]*)        # group 1: function name
            \s*
            \(([^)]*)\)                      # group 2: parameter list
            (?:\s*:\s*([^{]+?))?             # group 3: optional return type
            \s*
            \{                               # opening brace
        /ix';

        if (! preg_match_all($pattern, $blanked, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $symbols = [];
        $matchCount = count($matches[0]);
        for ($i = 0; $i < $matchCount; $i++) {
            $name = $matches[1][$i][0];
            if ($name === '') {
                continue;
            }
            $params = trim((string) $matches[2][$i][0]);
            $returnType = trim((string) $matches[3][$i][0]);
            // Offset in the BLANKED text of the opening brace. We find the
            // brace after the matched signature.
            $braceOffsetInBlanked = (int) $matches[0][$i][1] + strlen((string) $matches[0][$i][0]) - 1;

            // Walk forward from the opening brace, counting depth, stopping
            // at the matching closing brace. Operate on BLANKED text so
            // braces in strings/comments don't break the count.
            $closeOffsetInBlanked = $this->findMatchingBrace($blanked, $braceOffsetInBlanked);
            if ($closeOffsetInBlanked === null) {
                // Unbalanced; skip this symbol (do not crash).
                continue;
            }

            // Body in the BLANKED text (between the braces, exclusive).
            $bodyLength = $closeOffsetInBlanked - $braceOffsetInBlanked - 1;
            if ($bodyLength < 0) {
                $bodyLength = 0;
            }
            $body = $bodyLength > 0
                ? substr($source, $braceOffsetInBlanked + 1, $bodyLength)
                : '';

            $signature = 'function '.$name.'('.$params.')'
                .($returnType !== '' ? ': '.$returnType : '');

            $symbols[$name] = new ExtractedSymbol(
                name: $name,
                signature: $signature,
                body: $body,
            );
        }

        return $symbols;
    }

    /**
     * Find the position of the closing brace matching the opening brace at
     * $openPos, counting depth. Returns null when unbalanced. Operates on
     * the BLANKED source (strings/comments already replaced).
     */
    private function findMatchingBrace(string $blanked, int $openPos): ?int
    {
        $len = strlen($blanked);
        if ($openPos < 0 || $openPos >= $len || $blanked[$openPos] !== '{') {
            return null;
        }
        $depth = 0;
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $blanked[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Replace string literals and comments with blanks of equal length so
     * brace counting is not fooled by braces appearing inside strings or
     * comments. PHP has several comment/string forms; we handle:
     *   - line comments:    //... and #...
     *   - block comments:   /* ... *\/
     *   - single strings:   '...'
     *   - double strings:   "..."
     *
     * Heredoc/nowdoc are NOT blanked (they would need a state machine); the
     * brace counter treats their content as code, which is a known edge case
     * the conservative purity detector catches (heredoc bodies usually trip
     * an impure indicator). For the Atlas Dev pipeline's well-formed PHP,
     * this is sufficient.
     */
    private function blankStringsAndComments(string $source): string
    {
        // Order matters: block comments first (they may contain // or quotes).
        $blanked = preg_replace_callback(
            [
                '%/\*.*?\*/%s',              // block comment
                '%//[^\n]*%',                // line comment //
                '%\#[^\n]*%',                // line comment #
                "%'(?:\\\\.|[^'\\\\])*'%",   // single-quoted string
                '%"(?:\\\\.|[^"\\\\])*"%',   // double-quoted string
            ],
            static function (array $m): string {
                // Replace the match with spaces of equal length (preserve
                // offsets). Newlines inside the match are kept so line
                // numbers do not shift.
                $out = '';
                foreach (str_split($m[0]) as $ch) {
                    $out .= $ch === "\n" ? "\n" : ' ';
                }

                return $out;
            },
            $source,
        );

        return $blanked ?? $source;
    }
}

/**
 * An extracted function/method definition.
 */
final class ExtractedSymbol
{
    public function __construct(
        /** @var non-empty-string */
        public readonly string $name,
        /** @var non-empty-string */
        public readonly string $signature,
        /** The function body source (interior between braces). */
        public readonly string $body,
    ) {}
}

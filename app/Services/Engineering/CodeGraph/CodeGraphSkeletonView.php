<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · E-7 — Progressive disclosure for the code graph (skeleton-first reads).
 *
 * Reading a symbol's FULL body is expensive: a single fat class can spend thousands
 * of context tokens an agent did not ask for. Most of the time the agent only needs
 * the SHAPE — the signature, the kind, "is there more behind this?" — and will drill
 * into the few bodies that actually matter. This view inverts the default: it returns
 * signature-level SKELETONS first and a `drillable` map naming exactly which symbols
 * have an elided body the caller can fetch on demand.
 *
 * Per symbol, the skeleton keeps the SIGNATURE (the explicit `signature`, or the first
 * non-empty line of `content`/`body` when no signature was supplied) and ELIDES the
 * body when the symbol's content is larger than the elide threshold. An elided symbol
 * is marked `elided => true` and added to `drillable` so the caller has a precise drill
 * map. Symbols whose content already fits under the threshold are returned whole (their
 * "skeleton" IS the full thing) and are NOT drillable — there is nothing more to fetch.
 *
 * The `stats` block proves the economy: `approx_tokens_skeleton` (what this view costs)
 * versus `approx_tokens_full` (what reading every body outright would have cost), using
 * the house chars/4 token heuristic. When anything is elided the skeleton total is
 * strictly the cheaper of the two.
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider, no I/O. The same input
 *     always yields byte-identical output. Skeletons are emitted in input order; the
 *     `drillable` map is keyed by id and so is order-independent.
 *   - Never throws on bad data. Non-array symbols are skipped. Missing fields are
 *     tolerated: a symbol with neither signature nor content yields an empty-signature
 *     skeleton (and is not drillable — there is no body to elide). Non-scalar/garbage
 *     field values are coerced or ignored rather than fatal.
 *   - Char counts use mb_strlen so a multibyte body is measured by characters (matching
 *     the documented "content length" / "chars/4" contract), not raw bytes.
 *   - The elide threshold is read from config with an inline default literal (so it
 *     works with no config edit); `$opts['elide_chars']` overrides per call. A missing,
 *     non-numeric, NaN/INF, or negative threshold falls back to the default; the value
 *     is floored at 0 so a 0 threshold elides every non-empty body (the most aggressive,
 *     still well-defined, setting).
 *
 * This is [php] by the runtime-language boundary: it ORCHESTRATES disclosure (a policy /
 * shaping decision). The heavy AST skeletoniser that produces real signatures from source
 * is the [py] companion; this view operates on already-extracted symbol records.
 */
class CodeGraphSkeletonView
{
    public const SCHEMA = 'atlas.code_graph.skeleton_view.v1';

    /** House heuristic: ~4 characters per token for rough budget math. */
    private const CHARS_PER_TOKEN = 4;

    /** Inline default elide threshold (characters of content) — config can override. */
    private const DEFAULT_ELIDE_CHARS = 200;

    /**
     * Build signature-level skeletons with a drill map for on-demand full content.
     *
     * @param  array<int,mixed>  $symbols  symbols to skeletonise. Each SHOULD be an
     *   array of the shape ['id'?, 'signature'?, 'body'?|'content'?, 'kind'?]. Non-array
     *   entries are skipped. `content` is preferred over `body` when both are present.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `elide_chars` (int|float): elide a body when its content length exceeds this
     *     (default from config 'atlas.code_graph.skeleton_elide_chars', else 200).
     * @return array{
     *   skeletons: array<int,array{id:string, signature:string, kind:string, elided:bool}>,
     *   drillable: array<string,bool>,
     *   stats: array{
     *     total:int, elided:int,
     *     approx_tokens_skeleton:int, approx_tokens_full:int
     *   }
     * }
     *   `skeletons` preserves input order (skipped non-array entries leave no gap).
     *   `drillable` maps the id of every elided symbol to true. `stats.total` is the
     *   number of emitted skeletons; `approx_tokens_skeleton` ≤ `approx_tokens_full`.
     */
    public function skeletonize(array $symbols, array $opts = []): array
    {
        $elideChars = $this->resolveElideChars($opts);

        $skeletons = [];
        $drillable = [];
        $elidedCount = 0;
        $skeletonChars = 0;
        $fullChars = 0;

        $index = 0;
        foreach ($symbols as $symbol) {
            $currentIndex = $index++;

            if (! is_array($symbol)) {
                // Malformed entry: nothing to skeletonise. Skip it (fail-safe) — never
                // emit a phantom skeleton for something we cannot read.
                continue;
            }

            $id = $this->resolveId($symbol, $currentIndex);
            $kind = $this->stringField($symbol, 'kind');
            $signatureField = $this->stringField($symbol, 'signature');
            $content = $this->resolveContent($symbol);

            // The signature shown in the skeleton: the explicit signature wins; else the
            // first non-empty line of the content; else empty (tolerated).
            $signature = $signatureField !== ''
                ? $this->firstNonEmptyLine($signatureField)
                : $this->firstNonEmptyLine($content);

            $contentLen = $this->length($content);
            // Elide only when there IS a body AND it exceeds the threshold. A body that
            // already fits is returned whole and is not drillable (nothing more to fetch).
            $elided = $contentLen > 0 && $contentLen > $elideChars;

            if ($elided) {
                $elidedCount++;
                $drillable[$id] = true;
            }

            $skeletons[] = [
                'id' => $id,
                'signature' => $signature,
                'kind' => $kind,
                'elided' => $elided,
            ];

            // Token accounting. The skeleton always costs the signature; the full read
            // costs the whole content (or the signature when there is no separate body).
            $skeletonChars += $this->length($signature);
            $fullChars += $contentLen > 0 ? $contentLen : $this->length($signature);
        }

        return [
            'skeletons' => $skeletons,
            'drillable' => $drillable,
            'stats' => [
                'total' => count($skeletons),
                'elided' => $elidedCount,
                'approx_tokens_skeleton' => $this->tokens($skeletonChars),
                'approx_tokens_full' => $this->tokens($fullChars),
            ],
        ];
    }

    /**
     * Resolve the per-call elide threshold (characters of content). Opts override config;
     * config falls back to the inline default; the result is floored at 0 so it is always
     * a well-defined, non-negative bound.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveElideChars(array $opts): int
    {
        if (array_key_exists('elide_chars', $opts)) {
            $candidate = $this->intOrNull($opts['elide_chars']);
            if ($candidate !== null) {
                return max(0, $candidate);
            }
        }

        $configured = $this->intOrNull(config('atlas.code_graph.skeleton_elide_chars', self::DEFAULT_ELIDE_CHARS));

        return max(0, $configured ?? self::DEFAULT_ELIDE_CHARS);
    }

    /**
     * The symbol's id as a non-empty stable string. A missing/blank/non-scalar id is
     * replaced by a deterministic positional fallback so every emitted skeleton — and
     * thus every drillable entry — has a usable, collision-free key within one call.
     *
     * @param  array<string,mixed>  $symbol
     */
    private function resolveId(array $symbol, int $index): string
    {
        if (array_key_exists('id', $symbol) && is_scalar($symbol['id'])) {
            $id = trim((string) $symbol['id']);
            if ($id !== '') {
                return $id;
            }
        }

        return '__symbol_'.$index;
    }

    /**
     * Content body, preferring `content` over `body`. Returns '' when neither is a
     * usable string (non-string/garbage values are tolerated as absent).
     *
     * @param  array<string,mixed>  $symbol
     */
    private function resolveContent(array $symbol): string
    {
        foreach (['content', 'body'] as $field) {
            if (array_key_exists($field, $symbol) && is_string($symbol[$field])) {
                return $symbol[$field];
            }
        }

        return '';
    }

    /**
     * A string field's value, or '' when absent/non-string.
     *
     * @param  array<string,mixed>  $symbol
     */
    private function stringField(array $symbol, string $field): string
    {
        if (array_key_exists($field, $symbol) && is_string($symbol[$field])) {
            return trim($symbol[$field]);
        }

        return '';
    }

    /**
     * First non-empty (trimmed) line of a block, or '' if every line is blank. This is
     * what becomes the skeleton signature when no explicit signature was supplied.
     */
    private function firstNonEmptyLine(string $text): string
    {
        if ($text === '') {
            return '';
        }

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /** Character length (multibyte-aware) — matches the documented "chars" contract. */
    private function length(string $value): int
    {
        return mb_strlen($value);
    }

    /** chars/4 token estimate, rounded up; never negative. */
    private function tokens(int $chars): int
    {
        if ($chars <= 0) {
            return 0;
        }

        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }

    /**
     * Coerce a value to a non-NaN/non-INF integer, or null when it is not a usable
     * number. Floats are truncated toward zero (a fractional char threshold is meaningless).
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }

            return (int) $float;
        }

        return null;
    }
}

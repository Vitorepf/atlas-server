<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Support;

/**
 * CacheAligner core (AP-813): relocate clearly-volatile tokens out of the stable
 * prompt PREFIX into a labelled tail block, so a provider's KV-cache prefix is more
 * likely to stay byte-stable across calls (cache HIT instead of bust).
 *
 * INFORMATION-PRESERVING: each distinct volatile token in the prefix is replaced by
 * a stable, deterministic alias («ctxN») and its value is appended verbatim in a
 * labelled tail legend. Nothing is dropped — the value is still in the prompt, just
 * moved. Across two calls that differ ONLY in volatile values (e.g. the date), the
 * aliased prefix is now identical → the cache can hit.
 *
 * Honest scope: Atlas providers are CLI/stdin (no SDK messages[]+cache_control), so
 * this is best-effort prefix HYGIENE, NOT the SDK-path "up to 90% cached" number.
 * Conservative allowlist (timestamps, UUIDs, long hex ids) keeps it quality-safe.
 */
final class VolatileTokenRelocator
{
    /** @var list<string> */
    private const PATTERNS = [
        // ISO-8601 timestamps (with optional ms / tz)
        '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?\b/',
        // UUID v1–v5
        '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/',
        // long hex ids / hashes (24–64 hex chars)
        '/\b[0-9a-f]{24,64}\b/',
    ];

    /**
     * @param  int  $prefixChars  only relocate tokens found within the first N chars
     * @return array{prompt:string, relocated:int}
     */
    public function relocate(string $prompt, int $prefixChars = 600): array
    {
        if ($prompt === '') {
            return ['prompt' => $prompt, 'relocated' => 0];
        }

        $prefixLen = min($prefixChars, strlen($prompt));
        $prefix = substr($prompt, 0, $prefixLen);
        $rest = substr($prompt, $prefixLen);

        $aliasByValue = [];   // value => «ctxN» (dedup identical values)
        $legend = [];         // ordered alias => value
        $counter = 0;

        foreach (self::PATTERNS as $pattern) {
            $replaced = preg_replace_callback(
                $pattern,
                function (array $m) use (&$aliasByValue, &$legend, &$counter): string {
                    $value = $m[0];
                    if (! isset($aliasByValue[$value])) {
                        $counter++;
                        $alias = '<<ctx'.$counter.'>>';
                        $aliasByValue[$value] = $alias;
                        $legend[$alias] = $value;
                    }

                    return $aliasByValue[$value];
                },
                $prefix,
            );
            if (is_string($replaced)) {
                $prefix = $replaced;
            }
        }

        if ($legend === []) {
            return ['prompt' => $prompt, 'relocated' => 0];
        }

        $pairs = [];
        foreach ($legend as $alias => $value) {
            $pairs[] = $alias.'='.$value;
        }
        $tail = "\n\n[atlas:context cache-stable aliases — ".implode(' ', $pairs).']';

        return ['prompt' => $prefix.$rest.$tail, 'relocated' => count($legend)];
    }
}

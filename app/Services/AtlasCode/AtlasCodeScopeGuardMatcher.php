<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/**
 * Atlas Code · canonical path matcher for scope guard.
 *
 * Replaces the v1 regex shim with a fnmatch-based matcher that supports
 * the canonical `.gitignore`-style globs the Atlas Work Packet contract
 * actually uses:
 *
 *   - `*`        — matches anything except `/`
 *   - `**`       — matches anything including `/`
 *   - `?`        — single non-slash char
 *   - `[abc]`    — character class
 *   - leading `!` — negation (excludes paths matched by everything else)
 *   - patterns ending in `/` match directories (Atlas treats them as `pattern/**`)
 *
 * Paths are normalized to `posix-relative` form (no leading `./`, no
 * leading `/`, no `..`). Empty / whitespace patterns are ignored.
 */
final class AtlasCodeScopeGuardMatcher
{
    /**
     * Decide if `$path` is permitted by `$allowed` and not blocked by
     * `$forbidden`. Returns a structured decision so callers can render
     * blocker reasons.
     *
     * Semantics:
     *   - When `$allowed` is empty, the file is permitted unless forbidden
     *     explicitly matches.
     *   - When `$allowed` is non-empty, the file MUST match at least one
     *     positive (non-negated) allowed pattern AND not match a negation.
     *   - Forbidden always wins over allowed (defense in depth).
     *
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $forbidden
     * @return array{allowed: bool, reason: string}
     */
    public function decide(string $path, array $allowed, array $forbidden): array
    {
        $norm = $this->normalizePath($path);

        if ($this->isMatchedBy($norm, $forbidden, defaultIfEmpty: false)) {
            return ['allowed' => false, 'reason' => 'in_forbidden_files'];
        }

        if ($allowed === []) {
            return ['allowed' => true, 'reason' => 'no_allowed_files_declared'];
        }

        if ($this->isMatchedBy($norm, $allowed, defaultIfEmpty: false)) {
            return ['allowed' => true, 'reason' => 'matched_allowed_pattern'];
        }

        return ['allowed' => false, 'reason' => 'outside_allowed_files'];
    }

    /**
     * Bulk check: which paths violate the scope contract?
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $forbidden
     * @return array<int, array{file: string, reason: string}>
     */
    public function violations(array $paths, array $allowed, array $forbidden): array
    {
        $out = [];
        foreach ($paths as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decision = $this->decide($raw, $allowed, $forbidden);
            if (! $decision['allowed']) {
                $out[] = ['file' => $this->normalizePath($raw), 'reason' => $decision['reason']];
            }
        }

        return $out;
    }

    public function matches(string $path, string $pattern): bool
    {
        return $this->matchSinglePattern($this->normalizePath($path), $this->normalizePattern($pattern));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function isMatchedBy(string $normalizedPath, array $patterns, bool $defaultIfEmpty): bool
    {
        if ($patterns === []) {
            return $defaultIfEmpty;
        }
        $matched = false;
        foreach ($patterns as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $pattern = trim($raw);
            $negated = false;
            if (str_starts_with($pattern, '!')) {
                $negated = true;
                $pattern = ltrim(substr($pattern, 1));
            }
            $norm = $this->normalizePattern($pattern);
            if ($this->matchSinglePattern($normalizedPath, $norm)) {
                $matched = ! $negated;
            }
        }

        return $matched;
    }

    private function matchSinglePattern(string $path, string $pattern): bool
    {
        // fnmatch with FNM_PATHNAME handles `*` not matching `/`, but does
        // NOT support `**`. We pre-translate `**` to a regex slot, then run
        // the comparison via regex.
        $regex = $this->patternToRegex($pattern);

        return preg_match($regex, $path) === 1;
    }

    private function patternToRegex(string $pattern): string
    {
        $out = '';
        $i = 0;
        $len = strlen($pattern);
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '*' && $i + 1 < $len && $pattern[$i + 1] === '*') {
                // ** — matches any number of chars including `/`
                $out .= '.*';
                $i += 2;
                // optional `/` follow-up (so `src/**/x` matches `src/x` too)
                if ($i < $len && $pattern[$i] === '/') {
                    $out .= '/?';
                    $i++;
                }

                continue;
            }
            if ($c === '*') {
                $out .= '[^/]*';
                $i++;

                continue;
            }
            if ($c === '?') {
                $out .= '[^/]';
                $i++;

                continue;
            }
            if ($c === '[') {
                // character class — pass through but escape `/`
                $j = $i + 1;
                while ($j < $len && $pattern[$j] !== ']') {
                    $j++;
                }
                if ($j < $len) {
                    $out .= '['.substr($pattern, $i + 1, $j - $i - 1).']';
                    $i = $j + 1;

                    continue;
                }
            }
            $out .= preg_quote($c, '#');
            $i++;
        }

        return '#^'.$out.'$#u';
    }

    private function normalizePath(string $raw): string
    {
        $clean = trim($raw);
        if ($clean === '') {
            return '';
        }
        // Strip leading `./` and `/`
        $clean = preg_replace('#^\./+#', '', $clean) ?? $clean;
        $clean = ltrim($clean, '/');
        // Normalize backslashes (Windows) to forward slashes
        $clean = str_replace('\\', '/', $clean);
        // Collapse duplicate slashes
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;

        return $clean;
    }

    private function normalizePattern(string $raw): string
    {
        $pattern = trim($raw);
        $pattern = preg_replace('#^\./+#', '', $pattern) ?? $pattern;
        $pattern = ltrim($pattern, '/');
        $pattern = str_replace('\\', '/', $pattern);
        // Trailing `/` means "directory and everything under it".
        if (str_ends_with($pattern, '/')) {
            $pattern .= '**';
        }

        return $pattern;
    }
}

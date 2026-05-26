<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev;

/**
 * Atlas Dev Scope Guard (AP-701 / Patamar A3).
 *
 * Pure evaluator. Given:
 *   - a list of proposed write paths (provider patch targets),
 *   - the declared `allowed_files` set (operator-approved scope),
 *   - the declared `forbidden_files` set (explicit veto),
 *
 * returns a canonical `atlas.dev.scope_guard.v1` decision envelope.
 *
 * The service does NOT mutate or apply files. Callers (the future
 * post-provider writer step) MUST consult `decision` and refuse to
 * persist writes when it is `deny`.
 *
 * Matching rules:
 *   - Exact path match: allowed by exact equality.
 *   - Prefix match with `/**` suffix: `app/**` matches `app/Foo.php`,
 *     `app/Sub/Bar.php`, but NOT `database/migrations/x.php`.
 *   - Forbidden takes precedence over allowed.
 *   - Empty allowed set → deny all writes (conservative default).
 *   - Empty proposed writes → allow (no-op).
 */
final class AtlasDevScopeGuardService
{
    public const SCHEMA_VERSION = 'atlas.dev.scope_guard.v1';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_DENY = 'deny';

    public const REASON_NOT_IN_ALLOWED = 'not_in_allowed';

    public const REASON_EXPLICITLY_FORBIDDEN = 'explicitly_forbidden';

    public const REASON_ALLOWED_SET_EMPTY = 'allowed_set_empty';

    /**
     * @param  list<string>  $proposedWrites
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @return array<string,mixed>
     */
    public function evaluate(array $proposedWrites, array $allowedFiles, array $forbiddenFiles): array
    {
        $proposedWrites = $this->normalize($proposedWrites);
        $allowedFiles = $this->normalize($allowedFiles);
        $forbiddenFiles = $this->normalize($forbiddenFiles);

        // Empty proposed writes is allowed by definition (no-op).
        if ($proposedWrites === []) {
            return $this->envelope(
                decision: self::DECISION_ALLOW,
                allowedWrites: [],
                deniedWrites: [],
                reasonPerDenied: [],
                allowedSet: $allowedFiles,
                forbiddenSet: $forbiddenFiles,
            );
        }

        $allowedWrites = [];
        $deniedWrites = [];
        $reasonPerDenied = [];

        foreach ($proposedWrites as $path) {
            if ($this->matchesAny($path, $forbiddenFiles)) {
                $deniedWrites[] = $path;
                $reasonPerDenied[$path] = self::REASON_EXPLICITLY_FORBIDDEN;

                continue;
            }
            if ($allowedFiles === []) {
                $deniedWrites[] = $path;
                $reasonPerDenied[$path] = self::REASON_ALLOWED_SET_EMPTY;

                continue;
            }
            if (! $this->matchesAny($path, $allowedFiles)) {
                $deniedWrites[] = $path;
                $reasonPerDenied[$path] = self::REASON_NOT_IN_ALLOWED;

                continue;
            }
            $allowedWrites[] = $path;
        }

        return $this->envelope(
            decision: $deniedWrites === [] ? self::DECISION_ALLOW : self::DECISION_DENY,
            allowedWrites: $allowedWrites,
            deniedWrites: $deniedWrites,
            reasonPerDenied: $reasonPerDenied,
            allowedSet: $allowedFiles,
            forbiddenSet: $forbiddenFiles,
        );
    }

    /**
     * @param  list<string>  $allowedWrites
     * @param  list<string>  $deniedWrites
     * @param  array<string,string>  $reasonPerDenied
     * @param  list<string>  $allowedSet
     * @param  list<string>  $forbiddenSet
     * @return array<string,mixed>
     */
    private function envelope(
        string $decision,
        array $allowedWrites,
        array $deniedWrites,
        array $reasonPerDenied,
        array $allowedSet,
        array $forbiddenSet,
    ): array {
        $envelope = [
            'schema' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'allowed_writes' => array_values($allowedWrites),
            'denied_writes' => array_values($deniedWrites),
            'reason_per_denied' => $reasonPerDenied,
            'allowed_set_size' => count($allowedSet),
            'forbidden_set_size' => count($forbiddenSet),
        ];
        $envelope['evaluation_hash'] = $this->hashEnvelope($envelope, $allowedSet, $forbiddenSet);

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  list<string>  $allowedSet
     * @param  list<string>  $forbiddenSet
     */
    private function hashEnvelope(array $envelope, array $allowedSet, array $forbiddenSet): string
    {
        $material = [
            'd' => $envelope['decision'],
            'aw' => $envelope['allowed_writes'],
            'dw' => $envelope['denied_writes'],
            'rd' => $envelope['reason_per_denied'],
            'as' => $allowedSet,
            'fs' => $forbiddenSet,
        ];

        return 'sha256:'.hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, string $pattern): bool
    {
        if ($path === $pattern) {
            return true;
        }
        // `dir/**` matches any path that begins with `dir/`.
        if (str_ends_with($pattern, '/**')) {
            $prefix = substr($pattern, 0, -3);

            return str_starts_with($path, $prefix.'/');
        }
        // `dir/*` matches any path that begins with `dir/` AND has no
        // further `/` after the prefix. We support the common case
        // `dir/*.php` lazily by using fnmatch.
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            return fnmatch($pattern, $path);
        }

        return false;
    }

    /**
     * @param  list<string>  $list
     * @return list<string>
     */
    private function normalize(array $list): array
    {
        $clean = [];
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $clean[] = $item;
        }

        return array_values(array_unique($clean));
    }
}

<?php

namespace App\Support;

class AtlasSecurity
{
    private const REDACTED = '[redacted]';

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,string|false>
     */
    public static function processEnv(array $extra = [], string $profile = 'tool'): array
    {
        $current = self::currentEnvironment();
        $allowlist = array_unique(array_merge(
            self::stringList(self::config('atlas.ai.security.process_env.allowlist', [])),
            self::stringList(self::config("atlas.ai.security.process_env.profiles.{$profile}.allowlist", [])),
        ));
        $prefixes = array_unique(array_merge(
            self::stringList(self::config('atlas.ai.security.process_env.prefix_allowlist', [])),
            self::stringList(self::config("atlas.ai.security.process_env.profiles.{$profile}.prefix_allowlist", [])),
        ));

        $env = [];
        foreach ($current as $key => $value) {
            if (in_array($key, $allowlist, true) || self::hasAllowedPrefix($key, $prefixes)) {
                $env[$key] = $value;
            } else {
                $env[$key] = false;
            }
        }

        foreach ($extra as $key => $value) {
            if (! is_string($key) || $key === '' || is_array($value) || is_object($value)) {
                continue;
            }

            $env[$key] = (string) $value;
        }

        return $env;
    }

    public static function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redactString($value);
        }

        if (is_array($value)) {
            return self::redactArray($value);
        }

        return $value;
    }

    /**
     * Secret patterns, keyed by kind. Single source of truth for BOTH redaction
     * and detection.
     *
     * AtlasRetrievalPrivacyTrustLayerService — the gate deciding whether a
     * retrieved chunk may leave the machine for an external provider — carried
     * its own 3-pattern table, so a GitHub PAT, a JWT, an AWS key or a Slack
     * token passed the local-first export gate that this class would have
     * redacted. One owner now.
     *
     * @return array<string,string> kind => pattern
     */
    public static function secretPatterns(): array
    {
        return [
            'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            'jwt' => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            'openai_key' => '/\bsk-(?:proj-)?[A-Za-z0-9_-]{16,}\b/',
            'github_pat' => '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
            'github_token' => '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/',
            'gitlab_pat' => '/\bglpat-[A-Za-z0-9_-]{20,}\b/',
            'slack_token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
            'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
            'bearer' => '/\bBearer\s+[A-Za-z0-9._~+\/=-]{12,}\b/i',
            // O valor pode vir entre aspas ("password": "x") — a forma que segredo
            // toma em config JSON, payload de provider e receipt, ou seja a mais
            // comum neste corpus. Sem o ["\']? o detector so via `chave: valor`
            // cru: redactString redigia a forma JSON e secretDetections nao a
            // enxergava, entao a camada de privacidade deixava passar sem flagrar.
            'api_key' => '/\b(api[_-]?key|secret|password|senha|passwd|pwd|token|authorization|auth|cookie|session|private[_-]?key)["\']?\s*[:=]\s*["\']?[^\s,"\']{6,}/i',
        ];
    }

    /**
     * Which secret kinds appear in $value, and how many times each.
     *
     * @return array<string,int> kind => count
     */
    public static function secretDetections(string $value): array
    {
        $detections = [];
        foreach (self::secretPatterns() as $kind => $pattern) {
            preg_match_all($pattern, $value, $matches);
            $count = count($matches[0] ?? []);
            if ($count > 0) {
                $detections[$kind] = $count;
            }
        }

        return $detections;
    }

    public static function redactString(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // 'senha' fica na lista porque secretPatterns() ja a declara segredo: sem ela,
        // Atlas DETECTA `senha: ...` e mesmo assim a entrega verbatim ao provider.
        // O operador escreve em portugues; a chave em ingles sozinha nao cobre o corpus.
        $patterns = [
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => self::REDACTED,
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/' => '[jwt-redacted]',
            '/\bsk-(?:proj-)?[A-Za-z0-9_-]{16,}\b/' => 'sk-'.self::REDACTED,
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/' => 'github_pat_'.self::REDACTED,
            '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/' => 'gh_'.self::REDACTED,
            '/\bglpat-[A-Za-z0-9_-]{20,}\b/' => 'glpat-'.self::REDACTED,
            '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/' => 'xox-'.self::REDACTED,
            '/\bAKIA[0-9A-Z]{16}\b/' => 'AKIA'.self::REDACTED,
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{12,}\b/i' => 'Bearer '.self::REDACTED,
            '/\b((?:api[_-]?key|token|secret|password|senha|passwd|pwd|authorization|auth|cookie|session|private[_-]?key)\s*[:=]\s*)(["\']?)[^"\'\s,&;]+(\2)/i' => '$1$2'.self::REDACTED.'$3',
            '/(["\'](?:api[_-]?key|token|secret|password|senha|passwd|pwd|authorization|auth|cookie|session|private[_-]?key)["\']\s*:\s*["\'])([^"\']+)(["\'])/i' => '$1'.self::REDACTED.'$3',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $value);
            if (is_string($redacted)) {
                $value = $redacted;
            }
        }

        foreach (self::stringList(self::config('atlas.ai.security.redaction.extra_patterns', [])) as $pattern) {
            $redacted = @preg_replace($pattern, self::REDACTED, $value);
            if (is_string($redacted)) {
                $value = $redacted;
            }
        }

        return $value;
    }

    private static function config(string $key, mixed $default = null): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param  array<mixed,mixed>  $payload
     * @return array<mixed,mixed>
     */
    public static function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = self::redact($value);
        }

        return $redacted;
    }

    /**
     * @param  array<int,mixed>  $command
     * @return array<int,string>
     */
    public static function redactCommand(array $command): array
    {
        return array_map(fn (mixed $part): string => self::redactString((string) $part), $command);
    }

    public static function redactCommandValue(mixed $command): mixed
    {
        if (is_array($command)) {
            return self::redactCommand(array_values($command));
        }

        if (is_string($command)) {
            return self::redactString($command);
        }

        return $command;
    }

    public static function commandLineForDisplay(mixed $command): ?string
    {
        if (is_string($command)) {
            return self::redactString($command);
        }

        if (! is_array($command)) {
            return null;
        }

        return implode(' ', array_map(
            fn (mixed $part): string => self::shellQuote(self::redactString((string) $part)),
            $command,
        ));
    }

    public static function shellQuote(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        if (preg_match('/^[A-Za-z0-9_@%+=:,\.\/-]+$/', $value) === 1) {
            return $value;
        }

        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    public static function canonicalPath(string $path, ?string $base = null, bool $allowMissing = false): string
    {
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : rtrim((string) $base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;

        $resolved = realpath($candidate);
        if (is_string($resolved)) {
            return $resolved;
        }

        if (! $allowMissing) {
            return self::normalizePath($candidate);
        }

        $suffix = [];
        $cursor = $candidate;
        while ($cursor !== '' && $cursor !== DIRECTORY_SEPARATOR) {
            if (file_exists($cursor)) {
                $anchor = realpath($cursor) ?: self::normalizePath($cursor);

                return self::normalizePath($anchor.($suffix === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, array_reverse($suffix))));
            }

            $suffix[] = basename($cursor);
            $next = dirname($cursor);
            if ($next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return self::normalizePath($candidate);
    }

    public static function pathIsInside(string $path, string $root): bool
    {
        $path = self::canonicalPath($path, allowMissing: true);
        $root = self::canonicalPath($root, allowMissing: false);

        if ($path === '' || $root === '') {
            return false;
        }

        $pathWithSep = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $rootWithSep = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($pathWithSep, $rootWithSep);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.', ' '], '_', $key));
        $parts = array_values(array_filter(explode('_', $normalized)));

        if (str_contains($normalized, 'api_key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'private_key')
        ) {
            return true;
        }

        foreach (['token', 'secret', 'password', 'passwd', 'pwd', 'cookie', 'credential'] as $sensitivePart) {
            if (in_array($sensitivePart, $parts, true)) {
                return true;
            }
        }

        if (preg_match('/(^|_)(authorization|bearer|auth)_(token|secret|header|cookie)$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/(^|_)session_(token|secret|cookie)$/', $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    private static function currentEnvironment(): array
    {
        $values = [];
        $sources = [$_SERVER, $_ENV];
        $getenv = getenv();
        if (is_array($getenv)) {
            $sources[] = $getenv;
        }

        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if (! is_string($key) || $key === '' || is_array($value) || is_object($value)) {
                    continue;
                }

                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @return array<int,string>
     */
    private static function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $item): bool => is_string($item) && $item !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $prefixes
     */
    private static function hasAllowedPrefix(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (! $absolute) {
                    $segments[] = $segment;
                }

                continue;
            }

            $segments[] = $segment;
        }

        return ($absolute ? DIRECTORY_SEPARATOR : '').implode(DIRECTORY_SEPARATOR, $segments);
    }
}

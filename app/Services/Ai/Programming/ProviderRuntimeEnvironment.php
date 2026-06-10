<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

final class ProviderRuntimeEnvironment
{
    public static function resolveExecutable(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, '/') && is_file($binary) && is_executable($binary)) {
            return $binary;
        }

        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(':', $path) as $dir) {
            $candidate = rtrim($dir, '/').'/'.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function resolveNestedExecutable(string $root, string $relativePath, string $fallback): string
    {
        $candidate = rtrim($root, '/').'/'.ltrim($relativePath, '/');

        return is_file($candidate) && is_executable($candidate) ? $candidate : $fallback;
    }

    /**
     * @param  list<string>  $vars
     */
    public static function authState(array $vars): string
    {
        foreach ($vars as $var) {
            $value = getenv($var);
            if (is_string($value) && trim($value) !== '') {
                return 'configured';
            }
        }

        return 'missing';
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    public static function workspacePath(array $manifest): ?string
    {
        $workspace = data_get($manifest, 'workspace.path') ?? data_get($manifest, 'workspace_path') ?? $manifest['cwd'] ?? null;
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        return trim($workspace);
    }
}

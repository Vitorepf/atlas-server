<?php

namespace App\Services\Ai\SelfConstruction;

final class ReadinessPathPolicy
{
    /** @return list<string> */
    public static function changedFiles(?string $root = null, int $limit = 500): array
    {
        static $cached = [];

        $root ??= base_path();
        $cacheKey = $root.'|'.$limit;

        if (isset($cached[$cacheKey])) {
            return $cached[$cacheKey];
        }

        $commands = [
            'git -C '.escapeshellarg($root).' diff --name-only | head -n '.((string) $limit),
            'git -C '.escapeshellarg($root).' ls-files --others --exclude-standard | head -n '.((string) $limit),
        ];

        $files = [];
        foreach ($commands as $command) {
            $output = shell_exec($command);
            foreach (explode("\n", trim((string) $output)) as $line) {
                $path = trim($line);
                if ($path !== '') {
                    $files[] = $path;
                }
            }
        }

        $cached[$cacheKey] = array_values(array_unique($files));

        return $cached[$cacheKey];
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     */
    public static function classifyPath(string $path, array $allowed, array $forbidden): string
    {
        foreach ($forbidden as $pattern) {
            if (self::pathMatches($path, $pattern)) {
                return str_contains($pattern, 'voice') || str_contains($pattern, 'Kernel') ? 'hot_external' : 'forbidden';
            }
        }

        foreach ($allowed as $pattern) {
            if (self::pathMatches($path, $pattern)) {
                return 'allowed';
            }
        }

        return 'unknown';
    }

    public static function pathMatches(string $path, string $pattern): bool
    {
        if ($path === $pattern) {
            return true;
        }

        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($path, substr($pattern, 0, -3).'/');
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     */
    public static function hasHotScope(array $paths): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with($path, 'runtimes/python/voice_realtime/')
                || $path === 'runtimes/python/voice_realtime/**'
                || str_starts_with($path, 'app/Services/Ai/Voice/')
                || $path === 'app/Services/Ai/Voice/**'
                || $path === 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php'
                || $path === 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md') {
                return true;
            }
        }

        return false;
    }
}

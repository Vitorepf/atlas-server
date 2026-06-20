<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Load-bearing environment for Loop acceptance/verifier subprocesses.
 *
 * The Loop runs generated PHPUnit/Artisan commands inside throwaway worktrees. Those
 * children must never inherit the operator's real DB settings: a test that calls
 * Schema::dropIfExists() must mutate only an isolated sqlite :memory: database.
 */
final class AtlasLoopHermeticCommandEnvironment
{
    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,string|false>
     */
    public static function forAcceptance(array $extra = []): array
    {
        $env = [
            'APP_ENV' => 'testing',
            'APP_KEY' => self::appKey(),
            'ATLAS_TOKEN' => 'testing-atlas-token-with-enough-length',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'DB_HOST' => false,
            'DB_PORT' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            'ATLAS_AOBG_WORKSPACE_API_AUTO_ACTIVATE' => 'false',
            'ATLAS_CODE_GRAPH_REAL_EDGES' => 'false',
            'ATLAS_FAILURE_AUTO_FEED_ENABLED' => 'false',
            'ATLAS_HARNESS_AUTOPILOT_ENABLED' => 'false',
        ];

        $path = self::pathWithCurrentPhp();
        if ($path !== null) {
            $env['PATH'] = $path;
        }

        foreach ($extra as $key => $value) {
            if (! is_string($key) || $key === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $env[$key] = $value === false ? false : (string) $value;
        }

        return $env;
    }

    public static function writeTestingEnv(string $workspace, ?string $sourceEnvPath = null): void
    {
        if ($workspace === '' || ! is_dir($workspace)) {
            return;
        }

        $env = [
            'APP_NAME=Atlas',
            'APP_ENV=testing',
            'APP_KEY='.self::appKey($sourceEnvPath),
            'APP_DEBUG=true',
            'ATLAS_TOKEN=testing-atlas-token-with-enough-length',
            'APP_MAINTENANCE_DRIVER=file',
            'BCRYPT_ROUNDS=4',
            'BROADCAST_CONNECTION=null',
            'CACHE_STORE=array',
            'CACHE_DRIVER=array',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'DB_URL=',
            'MAIL_MAILER=array',
            'QUEUE_CONNECTION=sync',
            'SESSION_DRIVER=array',
            'PULSE_ENABLED=false',
            'TELESCOPE_ENABLED=false',
            'NIGHTWATCH_ENABLED=false',
            'ATLAS_AOBG_WORKSPACE_API_AUTO_ACTIVATE=false',
            'ATLAS_CODE_GRAPH_REAL_EDGES=false',
            'ATLAS_FAILURE_AUTO_FEED_ENABLED=false',
            'ATLAS_HARNESS_AUTOPILOT_ENABLED=false',
        ];

        @file_put_contents(rtrim($workspace, '/').'/.env.testing', implode("\n", $env)."\n");
    }

    private static function pathWithCurrentPhp(): ?string
    {
        $binary = PHP_BINARY;
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $binDir = \dirname($binary);
        if ($binDir === '' || $binDir === '.' || $binDir === DIRECTORY_SEPARATOR) {
            return null;
        }

        $currentPath = getenv('PATH');

        return (! is_string($currentPath) || $currentPath === '')
            ? $binDir
            : $binDir.PATH_SEPARATOR.$currentPath;
    }

    private static function appKey(?string $sourceEnvPath = null): string
    {
        $paths = array_values(array_filter([
            $sourceEnvPath,
            self::repoEnvPath(),
        ], static fn (?string $path): bool => is_string($path) && $path !== ''));

        foreach ($paths as $path) {
            $value = self::envFileValue($path, 'APP_KEY');
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        $fromEnv = getenv('APP_KEY');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return 'base64:'.base64_encode(str_repeat('a', 32));
    }

    private static function envFileValue(string $path, string $key): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, $key.'=')) {
                return trim(substr($line, strlen($key) + 1), " \t\"'");
            }
        }

        return null;
    }

    private static function repoEnvPath(): ?string
    {
        try {
            if (function_exists('base_path')) {
                $path = base_path('.env');
                if (is_string($path) && $path !== '') {
                    return $path;
                }
            }
        } catch (\Throwable) {
            // Plain PHPUnit tests may have only the container helper loaded.
        }

        $cwd = getcwd();

        return is_string($cwd) && $cwd !== '' ? rtrim($cwd, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.env' : null;
    }
}

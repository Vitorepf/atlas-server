<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

final class AtlasDevProcessEnvironment
{
    /**
     * @return array<string, string>
     */
    public static function verificationCommandEnv(): array
    {
        return self::presentRuntimeEnv(['HOME', 'PATH', 'USER', 'LOGNAME']);
    }

    /**
     * Claude Code stores auth/session metadata under HOME. This env keeps the
     * provider process aligned with the operator shell without leaking more
     * runtime variables than the old gateway contract exposed.
     *
     * @return array<string, string>
     */
    public static function claudeProviderEnv(): array
    {
        $env = [];
        $home = self::runtimeEnvValue('HOME') ?? self::posixHome();
        if ($home !== null) {
            $env['HOME'] = rtrim($home, '/');

            $claudeConfigDir = self::runtimeEnvValue('CLAUDE_CONFIG_DIR');
            if ($claudeConfigDir !== null) {
                $env['CLAUDE_CONFIG_DIR'] = $claudeConfigDir;
            }
        }

        return array_merge($env, self::presentRuntimeEnv(['PATH', 'USER', 'LOGNAME']));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function presentRuntimeEnv(array $keys): array
    {
        $env = [];
        foreach ($keys as $key) {
            $value = self::runtimeEnvValue($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    private static function runtimeEnvValue(string $key): ?string
    {
        $value = getenv($key);
        if (! is_string($value) || trim($value) === '') {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function posixHome(): ?string
    {
        if (! function_exists('posix_getpwuid')) {
            return null;
        }

        $user = posix_getpwuid(posix_getuid());

        return is_array($user) && is_string($user['dir'] ?? null) && trim($user['dir']) !== ''
            ? $user['dir']
            : null;
    }
}

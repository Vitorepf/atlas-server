<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Safety;

/**
 * Central denylist for database-destructive artisan commands.
 *
 * These commands can wipe live Postgres when a sandbox leaks APP_ENV/.env
 * (the 15/06→02/07 wiper incident). Quality-first execution may retry and
 * spend tokens, but it must never auto-run destructive DB resets.
 */
final class DestructiveDatabaseCommandPolicy
{
    public const SCHEMA_VERSION = 'atlas.engineering_kernel.destructive_db_command_policy.v1';

    /**
     * @var list<string>
     */
    public const DENIED_TOKENS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'db:wipe --force',
        'schema:drop',
        'migrate:fresh --seed',
        'migrate:refresh --seed',
    ];

    public static function reasonIfDestructive(string $command): ?string
    {
        $normalised = strtolower(preg_replace('/\s+/u', ' ', trim($command)) ?? trim($command));
        $padded = ' '.$normalised.' ';

        foreach (self::DENIED_TOKENS as $token) {
            $needle = ' '.strtolower($token).' ';
            if (str_contains($padded, $needle) || str_contains($normalised, strtolower($token))) {
                return 'matched_destructive_db_token:'.$token;
            }
        }

        return null;
    }

    public static function isDestructive(string $command): bool
    {
        return self::reasonIfDestructive($command) !== null;
    }
}

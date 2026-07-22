<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * @deprecated Remove after the M2b compatibility cycle once legacy Cognitive
 *             FQCNs have drained from queues and deployed workers.
 */
final class CognitiveNamespaceAlias
{
    private const LEGACY_PREFIX = 'App\\Services\\Ai\\Cognitive\\';

    private const CANONICAL_PREFIX = 'App\\Services\\Ai\\Learning\\';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            if (! str_starts_with($class, self::LEGACY_PREFIX)) {
                return;
            }

            $canonical = self::CANONICAL_PREFIX.substr($class, strlen(self::LEGACY_PREFIX));
            if (! class_exists($canonical)) {
                return;
            }

            class_alias($canonical, $class);
        }, true, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Deprecation Notifier.
 *
 * Single source for the deprecation banner that every legacy rivals command
 * must emit on the way to the canonical `atlas:forge:rivals` entrypoint.
 *
 * In production it writes to STDERR (so JSON on STDOUT stays machine-clean).
 * Tests call `startCapture()` to redirect notifications into a static buffer
 * for assertion without parsing STDERR streams from `Artisan::call`.
 *
 * Static state is intentional: it survives across the kernel boots that
 * Artisan::call performs inside a single PHPUnit test.
 */
final class ForgeRivalsDeprecationNotifier
{
    /** @var list<array{legacy_command:string,canonical_action:string,message:string,at:string}> */
    private static array $captured = [];

    private static bool $capturing = false;

    public function notify(string $legacyCommand, string $canonicalAction): void
    {
        $message = sprintf(
            "[DEPRECATED] %s is deprecated. Use: php artisan atlas:forge:rivals %s\n".
            "[DEPRECATED] Canonical doc: docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md\n",
            $legacyCommand,
            $canonicalAction
        );

        if (self::$capturing) {
            self::$captured[] = [
                'legacy_command' => $legacyCommand,
                'canonical_action' => $canonicalAction,
                'message' => $message,
                'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ];

            return;
        }

        $stderr = defined('STDERR') ? \STDERR : fopen('php://stderr', 'w');
        if (is_resource($stderr)) {
            fwrite($stderr, $message);
        }
    }

    public static function startCapture(): void
    {
        self::$capturing = true;
        self::$captured = [];
    }

    public static function stopCapture(): void
    {
        self::$capturing = false;
        self::$captured = [];
    }

    /**
     * @return list<array{legacy_command:string,canonical_action:string,message:string,at:string}>
     */
    public static function captured(): array
    {
        return self::$captured;
    }
}

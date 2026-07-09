<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Throwable;

/**
 * §0 · THE AUTÔNOMOS MASTER ON/OFF SWITCH — global gate for Autônomos (brain+task) farm vectors.
 *
 * Class name remains AtlasLoopMasterSwitch (keep-list); preferred env is ATLAS_AUTONOMOS_MASTER_ENABLED
 * with fallback ATLAS_LOOP_MASTER_ENABLED (Obra 1 dual-read). Alias class: AtlasAutonomosMasterSwitch.
 *
 * INVARIANTS (all load-bearing):
 *   - DEFAULT FALSE / FAIL-CLOSED. Absent flag, unreadable .env, parse error, ANY exception ⇒ OFF.
 *   - ROBUST UNDER config:cache. We parse the .env file DIRECTLY (not config()/env()).
 *   - PÉTREO: in AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS — Autônomos can never flip itself on.
 *     Operator flips via atlas:agents:on|off autonomos (alias loop).
 */
final class AtlasLoopMasterSwitch
{
    /** Preferred env key (Obra 1). */
    public const KEY_AUTONOMOS = 'ATLAS_AUTONOMOS_MASTER_ENABLED';

    /** Legacy env key — still written + read as fallback. */
    public const KEY = 'ATLAS_LOOP_MASTER_ENABLED';

    private const TRUTHY = ['1', 'true', 'on', 'yes', 'enabled'];

    /** Test seam ONLY — point the switch at a temp .env. Never set in production code. */
    public static ?string $envPathOverride = null;

    /**
     * Is Autônomos globally permitted to run/respawn? FAIL-CLOSED.
     * Prefers ATLAS_AUTONOMOS_MASTER_ENABLED when present; else ATLAS_LOOP_MASTER_ENABLED.
     */
    public static function enabled(): bool
    {
        try {
            $raw = self::rawValue();

            return $raw !== null && in_array(strtolower(trim($raw)), self::TRUTHY, true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function state(): string
    {
        return self::enabled() ? 'on' : 'off';
    }

    public static function on(): bool
    {
        return self::write('true');
    }

    public static function off(): bool
    {
        return self::write('false');
    }

    private static function envPath(): string
    {
        return self::$envPathOverride ?? base_path('.env');
    }

    /**
     * Dual-read: last assignment of KEY_AUTONOMOS wins if present; else last KEY (legacy).
     */
    private static function rawValue(): ?string
    {
        $path = self::envPath();
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }
        $autonomos = null;
        $legacy = null;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            if (str_starts_with($trimmed, self::KEY_AUTONOMOS.'=')) {
                $autonomos = trim(substr($trimmed, strlen(self::KEY_AUTONOMOS) + 1), " \t\"'");
            } elseif (str_starts_with($trimmed, self::KEY.'=')) {
                $legacy = trim(substr($trimmed, strlen(self::KEY) + 1), " \t\"'");
            }
        }

        return $autonomos ?? $legacy;
    }

    /** Write both preferred + legacy keys so dual-read and old watchdogs stay consistent. */
    private static function write(string $value): bool
    {
        try {
            $path = self::envPath();
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            foreach ([self::KEY_AUTONOMOS, self::KEY] as $key) {
                $line = $key.'='.$value;
                if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
                    $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
                } else {
                    $contents = rtrim($contents, "\n")."\n".$line."\n";
                    if ($contents !== '' && $contents[0] === "\n") {
                        $contents = ltrim($contents, "\n");
                    }
                }
            }

            return file_put_contents($path, $contents) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}

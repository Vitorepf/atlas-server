<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Throwable;

/**
 * §0 · THE MASTER ON/OFF SWITCH — the single global gate that decides whether ANY part of the loop is allowed
 * to run or respawn. Built because the loop was auto-respawning (launchd scheduler → keepalive → resurrect
 * every stuck campaign) and burning tokens with no operator request.
 *
 * INVARIANTS (all load-bearing):
 *   - DEFAULT FALSE / FAIL-CLOSED. Absent flag, unreadable .env, parse error, ANY exception ⇒ OFF. The loop
 *     is OFF unless the operator EXPLICITLY turned it on. Silence = off.
 *   - ROBUST UNDER config:cache. We do NOT read config()/env() (env() returns null when the config is cached —
 *     the exact trap that would make a "safe" flag silently stale). We parse the .env file DIRECTLY, so the
 *     value is whatever the operator last wrote, cache or no cache. The bash watchdogs grep the SAME line.
 *   - ONE source of truth: the {@see KEY} line in the .env file. {@see on}/{@see off} rewrite that one line.
 *   - PÉTREO: this class is in AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS — the loop can never edit its own
 *     master switch (it can never turn itself back on). Only the operator (via atlas:loop:on/off) flips it.
 *
 * Every auto-start vector (keepalive, campaign launch, the watchdog scripts, the loop schedule entries) checks
 * {@see enabled} BEFORE doing anything. OFF ⇒ every one of them is a byte-identical no-op.
 */
final class AtlasLoopMasterSwitch
{
    public const KEY = 'ATLAS_LOOP_MASTER_ENABLED';

    private const TRUTHY = ['1', 'true', 'on', 'yes', 'enabled'];

    /** Test seam ONLY — point the switch at a temp .env. Never set in production code. */
    public static ?string $envPathOverride = null;

    /**
     * Is the loop globally permitted to run/respawn? FAIL-CLOSED: anything other than an explicit truthy flag
     * in the .env returns false.
     */
    public static function enabled(): bool
    {
        try {
            $raw = self::rawValue();

            return $raw !== null && in_array(strtolower(trim($raw)), self::TRUTHY, true);
        } catch (Throwable) {
            return false; // fail-CLOSED — an unreadable/corrupt source means OFF, never "assume on"
        }
    }

    /** Human/JSON-friendly state for command output + logs. */
    public static function state(): string
    {
        return self::enabled() ? 'on' : 'off';
    }

    /** Turn the loop ON (operator only). Returns true on a successful write. */
    public static function on(): bool
    {
        return self::write('true');
    }

    /** Turn the loop OFF (operator only). Returns true on a successful write. */
    public static function off(): bool
    {
        return self::write('false');
    }

    private static function envPath(): string
    {
        return self::$envPathOverride ?? base_path('.env');
    }

    /** The raw flag value from the .env file, or null when absent/unreadable. */
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
        $value = null;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            if (str_starts_with($trimmed, self::KEY.'=')) {
                // last assignment wins (mirrors dotenv); strip surrounding quotes/whitespace
                $value = trim(substr($trimmed, strlen(self::KEY) + 1), " \t\"'");
            }
        }

        return $value;
    }

    /** Idempotently set the .env flag line to $value, creating it if missing. Fail-safe (never throws). */
    private static function write(string $value): bool
    {
        try {
            $path = self::envPath();
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            $line = self::KEY.'='.$value;

            if (preg_match('/^'.preg_quote(self::KEY, '/').'=.*$/m', $contents) === 1) {
                $contents = (string) preg_replace('/^'.preg_quote(self::KEY, '/').'=.*$/m', $line, $contents);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
                if ($contents[0] === "\n") {
                    $contents = ltrim($contents, "\n");
                }
            }

            return file_put_contents($path, $contents) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}

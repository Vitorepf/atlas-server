<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

use Throwable;

/**
 * A fail-closed boolean flag stored as ONE line in the .env file, read by parsing the file DIRECTLY (not
 * via config()/env(), which return null when the config is cached — the exact trap that makes a "safe" flag
 * silently stale). This is the reusable mechanism behind the loop's §0 master switch generalized so the
 * fleet-wide gate ({@see AtlasFleetMasterSwitch}) and the bash watchdogs can grep the SAME source of truth.
 *
 * FAIL-CLOSED: absent flag, unreadable .env, parse error, ANY exception ⇒ false. Only an explicit truthy
 * value is true.
 */
final class EnvFlagGate
{
    private const TRUTHY = ['1', 'true', 'on', 'yes', 'enabled'];

    public static function isOn(string $path, string $key): bool
    {
        try {
            $raw = self::rawValue($path, $key);

            return $raw !== null && in_array(strtolower(trim($raw)), self::TRUTHY, true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function rawValue(string $path, string $key): ?string
    {
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
            if (str_starts_with($trimmed, $key.'=')) {
                $value = trim(substr($trimmed, strlen($key) + 1), " \t\"'"); // last assignment wins
            }
        }

        return $value;
    }

    /** Idempotently set the flag line, creating it if missing. Fail-safe (never throws). */
    public static function write(string $path, string $key, string $value): bool
    {
        try {
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            $line = $key.'='.$value;

            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
                $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
                if ($contents !== '' && $contents[0] === "\n") {
                    $contents = ltrim($contents, "\n");
                }
            }

            return file_put_contents($path, $contents) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Throwable;

/**
 * PART 2 — the DEDICATED task-serving gate, decoupled from the autonomous-loop master switch.
 *
 * WHY SEPARATE: the {@see AtlasLoopMasterSwitch} arms the whole AUTONOMOUS evolution loop (campaign launch,
 * keepalive respawn, auto-merge schedules) — turning it on can burn tokens with no operator request. But the
 * operator may want to serve tasks to EXTERNAL AIs (Claude Code/Codex/Cursor pulling `atlas:task next` and
 * committing their own work) WITHOUT arming that autonomous farm. This switch enables exactly that surface and
 * nothing else.
 *
 * FAIL-CLOSED, same .env-line discipline as the master switch (robust under config:cache). Serving is enabled
 * iff THIS flag is truthy OR the master switch is on (so a fully-armed loop also serves). The operator flips it
 * with {@see on}/{@see off} (atlas:task:serving). It is NOT pétreo — it governs an external-facing surface the
 * operator owns, not the self-modifying loop.
 */
final class AtlasTaskServingSwitch
{
    public const KEY = 'ATLAS_TASK_SERVING_ENABLED';

    private const TRUTHY = ['1', 'true', 'on', 'yes', 'enabled'];

    /** Test seam ONLY — point the switch at a temp .env. */
    public static ?string $envPathOverride = null;

    /** Is the task-serving surface (next/report) permitted? Own flag OR the autonomous master switch. */
    public static function enabled(): bool
    {
        if (AtlasLoopMasterSwitch::enabled()) {
            return true;
        }
        try {
            $raw = self::rawValue();

            return $raw !== null && in_array(strtolower(trim($raw)), self::TRUTHY, true);
        } catch (Throwable) {
            return false; // fail-CLOSED
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
                $value = trim(substr($trimmed, strlen(self::KEY) + 1), " \t\"'");
            }
        }

        return $value;
    }

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

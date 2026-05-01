<?php

namespace App\Services\Ai\Cli;

class AtlasTerminalTheme
{
    public const OK = '32';

    public const ERROR = '31';

    public const RISK = '33';

    public const MUTED = '2';

    public const ACCENT = '36';

    public const BOLD = '1';

    public const ITALIC = '3';

    public const DIM_ITALIC = '2;3';

    public static function ok(string $text, bool $decorated = true): string
    {
        return self::wrap(self::OK, $text, $decorated);
    }

    public static function error(string $text, bool $decorated = true): string
    {
        return self::wrap(self::ERROR, $text, $decorated);
    }

    public static function risk(string $text, bool $decorated = true): string
    {
        return self::wrap(self::RISK, $text, $decorated);
    }

    public static function muted(string $text, bool $decorated = true): string
    {
        return self::wrap(self::MUTED, $text, $decorated);
    }

    public static function accent(string $text, bool $decorated = true): string
    {
        return self::wrap(self::ACCENT, $text, $decorated);
    }

    public static function bold(string $text, bool $decorated = true): string
    {
        return self::wrap(self::BOLD, $text, $decorated);
    }

    public static function italic(string $text, bool $decorated = true): string
    {
        return self::wrap(self::ITALIC, $text, $decorated);
    }

    public static function dimItalic(string $text, bool $decorated = true): string
    {
        return self::wrap(self::DIM_ITALIC, $text, $decorated);
    }

    public static function status(string $level, string $text, bool $decorated = true): string
    {
        return match ($level) {
            'ok', 'passed', 'succeeded', 'success' => self::ok($text, $decorated),
            'error', 'failed', 'fail' => self::error($text, $decorated),
            'risk', 'warn', 'warning', 'needs_review' => self::risk($text, $decorated),
            'accent', 'info' => self::accent($text, $decorated),
            default => self::muted($text, $decorated),
        };
    }

    public static function wrap(string $code, string $text, bool $decorated): string
    {
        if (! $decorated) {
            return $text;
        }

        return "\033[".$code.'m'.$text."\033[0m";
    }
}

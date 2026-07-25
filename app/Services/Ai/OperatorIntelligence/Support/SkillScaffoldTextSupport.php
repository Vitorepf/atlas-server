<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Support\YesNo;
use Illuminate\Support\Str;

/**
 * Pure skill scaffold text sanitize helpers (full-pass peel).
 */
final class SkillScaffoldTextSupport
{
    public static function sanitizeSummary(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        $danger = '/(~\/\.ssh|id_rsa|rm\s+-rf|\bcurl\b|\bwget\b|\bnc\b|\/etc\/passwd|allowed[_-]tools|trust\s*:|tier\s*:|\$\(|`|\bbase64\b|\/dev\/tcp|\bsudo\b|\beval\b)/i';
        if (preg_match($danger, $s) === 1) {
            return '[recorrência redigida — conteúdo potencialmente perigoso ou sensível]';
        }

        return Str::limit($s, 300, '');
    }

    public static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return YesNo::trueFalse($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Defense-in-depth: never let a newline/control char reach a YAML scalar (a multi-line
        // scalar would be re-read as injected top-level keys by the naive frontmatter parsers).
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
        // Quote anything with YAML-special chars or leading/trailing space; escape quotes.
        if ($s === '' || preg_match('/[:#\-\[\]{}",\n]/', $s) === 1 || trim($s) !== $s) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
        }

        return $s;
    }
}

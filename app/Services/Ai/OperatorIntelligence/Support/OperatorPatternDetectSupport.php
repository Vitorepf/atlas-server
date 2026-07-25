<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure operator pattern shingle / regularity helpers (full-pass peel).
 */
final class OperatorPatternDetectSupport
{
    public static function shingle(string $claim): string
    {
        $norm = Str::ascii(mb_strtolower($claim));
        $norm = preg_replace('/[^a-z0-9 ]+/', ' ', $norm) ?? '';
        $tokens = array_values(array_filter(explode(' ', (string) preg_replace('/\s+/', ' ', $norm))));
        sort($tokens);

        return implode(' ', array_slice($tokens, 0, 6));
    }

    public static function patternId(string $kind, string $signature): string
    {
        return substr(hash('sha256', $kind.'|'.$signature), 0, 48);
    }

    /**
     * @param  list<float>  $confidences
     */
    public static function confidenceFromValues(array $confidences, int $groupCount, float $extraBonus): float
    {
        $confs = array_values(array_filter($confidences, static fn (float $c): bool => $c > 0.0));
        $base = $confs === [] ? 0.5 : (array_sum($confs) / count($confs));
        $n = max(1, $groupCount);
        $occBonus = min(0.25, 0.08 * log($n));

        return max(0.0, min(0.98, $base + $occBonus + max(0.0, $extraBonus)));
    }

    /**
     * @param  list<string>  $dates
     */
    public static function regularity(array $dates): float
    {
        $ts = array_values(array_filter(array_map(static fn (string $d): int => (int) strtotime($d), $dates)));
        sort($ts);
        if (count($ts) < 2) {
            return 0.0;
        }
        $gaps = [];
        for ($i = 1; $i < count($ts); $i++) {
            $gaps[] = ($ts[$i] - $ts[$i - 1]) / 86400.0;
        }
        $mean = array_sum($gaps) / count($gaps);
        if ($mean <= 0.0) {
            return 0.0;
        }
        $var = 0.0;
        foreach ($gaps as $g) {
            $var += ($g - $mean) ** 2;
        }
        $var /= count($gaps);
        $cov = sqrt($var) / $mean;

        return max(0.0, min(1.0, 1.0 - $cov));
    }

    public static function dowName(int $dowIso): string
    {
        return ['', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'][$dowIso] ?? (string) $dowIso;
    }
}

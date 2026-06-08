<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Support;

/**
 * "How many to keep" + near-duplicate detection helpers (AP-813), used by the
 * statistical compressors (SmartCrusher). Pure, deterministic, stdlib-only — the
 * light PHP form of headroom's adaptive_sizer (Kneedle knee + SimHash dedup). The
 * heavy/ML form (numpy/entropy/ModernBERT) is FUTURE-GATED in a Python runtime per
 * AP-813 §4; this stdlib form is enough to be information-preserving in practice.
 */
final class AdaptiveSizer
{
    /**
     * Kneedle-style knee on a DESCENDING importance/coverage curve: the count to
     * keep before diminishing returns. Uses the classic "max distance from the
     * chord" between the first and last normalized points. Bounded to [min,max].
     *
     * @param  list<int|float>  $descendingValues  per-item importance, sorted desc
     */
    public static function knee(array $descendingValues, int $min = 1, int $max = 40): int
    {
        $n = count($descendingValues);
        if ($n === 0) {
            return max(0, $min);
        }
        if ($n <= $min) {
            return $n;
        }

        // Cumulative coverage, normalized to [0,1] on both axes.
        $total = 0.0;
        foreach ($descendingValues as $v) {
            $total += max(0.0, (float) $v);
        }
        if ($total <= 0.0) {
            return self::clamp($min, $min, min($max, $n));
        }

        $cum = 0.0;
        $coverage = [];
        foreach ($descendingValues as $i => $v) {
            $cum += max(0.0, (float) $v);
            $coverage[$i] = $cum / $total; // y in [0,1], increasing, concave
        }

        // Chord from (0, y0) to (n-1, y_{n-1}); knee = index of max vertical gap.
        $x0 = 0.0;
        $y0 = $coverage[0];
        $x1 = (float) ($n - 1);
        $y1 = $coverage[$n - 1];
        $denom = ($x1 - $x0) ?: 1.0;

        $bestIdx = 0;
        $bestGap = -1.0;
        foreach ($coverage as $i => $y) {
            $chordY = $y0 + ($y1 - $y0) * (((float) $i - $x0) / $denom);
            $gap = $y - $chordY; // concave curve sits above the chord
            if ($gap > $bestGap) {
                $bestGap = $gap;
                $bestIdx = $i;
            }
        }

        // Keep through the knee (inclusive), then clamp.
        return self::clamp($bestIdx + 1, $min, min($max, $n));
    }

    /** 64-bit SimHash (hex) of a string, over whitespace tokens. */
    public static function simhash(string $text): string
    {
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $bits = array_fill(0, 64, 0);

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            // 64-bit feature hash from two 32-bit crc halves (deterministic).
            $h = sprintf('%08x%08x', crc32($token), crc32(strrev($token)));
            for ($i = 0; $i < 64; $i++) {
                $nibble = hexdec($h[intdiv($i, 4)]);
                $bit = ($nibble >> (3 - ($i % 4))) & 1;
                $bits[$i] += $bit === 1 ? 1 : -1;
            }
        }

        $out = 0;
        $hex = '';
        for ($i = 0; $i < 64; $i++) {
            $out = ($out << 1) | ($bits[$i] > 0 ? 1 : 0);
            if (($i + 1) % 4 === 0) {
                $hex .= dechex($out & 0xF);
                $out = 0;
            }
        }

        return $hex;
    }

    /** Hamming distance between two equal-length hex simhashes (0 = identical). */
    public static function hamming(string $a, string $b): int
    {
        $len = min(strlen($a), strlen($b));
        $distance = 0;
        for ($i = 0; $i < $len; $i++) {
            $xor = hexdec($a[$i]) ^ hexdec($b[$i]);
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($value, max($min, $max)));
    }
}

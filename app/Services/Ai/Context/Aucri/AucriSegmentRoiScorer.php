<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Aucri;

use App\Services\Ai\Context\AtlasContextStringListNormalizer;

final class AucriSegmentRoiScorer
{
    private const SCHEMA_VERSION = 'atlas.aucri.segment_roi_scoring.v1';

    private const REASON_MUST_KEEP = 'must_keep_pinned';

    private const REASON_BELOW_MEDIAN = 'below_median_roi_per_token';

    private const REASON_AT_OR_ABOVE_MEDIAN = 'at_or_above_median_roi_per_token';

    private const REASON_INSUFFICIENT = 'insufficient_segments_for_roi_cut';

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @return array{
     *     schema_version: string,
     *     scored: array<int, array{ref: string, tokens: int, utility: float, must_keep: bool, roi_per_token: float, drop_candidate: bool, reason: string}>,
     *     dropped_candidates: array<int, string>,
     *     kept_must_keep: array<int, string>,
     *     total_tokens: int,
     *     retained_tokens: int,
     *     reasons: array<int, string>
     * }
     */
    public function score(array $segments): array
    {
        $normalized = [];

        foreach ($segments as $segment) {
            $segment = is_array($segment) ? $segment : [];

            $ref = isset($segment['ref']) ? (string) $segment['ref'] : '';
            $tokens = $this->intValue($segment, 'tokens');
            $utility = $this->floatValue($segment, 'utility');
            $mustKeep = ($segment['must_keep'] ?? false) === true;

            $normalized[] = [
                'ref' => $ref,
                'tokens' => $tokens,
                'utility' => $utility,
                'must_keep' => $mustKeep,
                'roi_per_token' => round($utility / max(1, $tokens), 6),
            ];
        }

        $nonMustKeepRois = [];
        foreach ($normalized as $segment) {
            if ($segment['must_keep'] === false) {
                $nonMustKeepRois[] = $segment['roi_per_token'];
            }
        }

        $nonMustKeepCount = count($nonMustKeepRois);
        $hasRoiCut = $nonMustKeepCount >= 2;
        $median = $hasRoiCut ? $this->median($nonMustKeepRois) : 0.0;

        $scored = [];
        $droppedCandidates = [];
        $keptMustKeep = [];
        $totalTokens = 0;
        $retainedTokens = 0;
        $reasons = [];

        foreach ($normalized as $segment) {
            $totalTokens += $segment['tokens'];

            if ($segment['must_keep'] === true) {
                $dropCandidate = false;
                $reason = self::REASON_MUST_KEEP;
                $keptMustKeep[] = $segment['ref'];
            } elseif (! $hasRoiCut) {
                $dropCandidate = false;
                $reason = self::REASON_INSUFFICIENT;
            } elseif ($segment['roi_per_token'] < $median) {
                $dropCandidate = true;
                $reason = self::REASON_BELOW_MEDIAN;
            } else {
                $dropCandidate = false;
                $reason = self::REASON_AT_OR_ABOVE_MEDIAN;
            }

            if ($dropCandidate) {
                $droppedCandidates[] = $segment['ref'];
            } else {
                $retainedTokens += $segment['tokens'];
            }

            $reasons[] = $reason;

            $scored[] = [
                'ref' => $segment['ref'],
                'tokens' => $segment['tokens'],
                'utility' => $segment['utility'],
                'must_keep' => $segment['must_keep'],
                'roi_per_token' => $segment['roi_per_token'],
                'drop_candidate' => $dropCandidate,
                'reason' => $reason,
            ];
        }

        usort($scored, static function (array $left, array $right): int {
            if ($left['roi_per_token'] === $right['roi_per_token']) {
                // strcmp keeps the ref tie-break a lexicographic string ASC ordering,
                // consistent with the SORT_STRING contract used across the kernels:
                // numeric-string refs ("2","10","100") must not coerce to numeric order.
                return strcmp($left['ref'], $right['ref']);
            }

            return $right['roi_per_token'] <=> $left['roi_per_token'];
        });

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scored' => $scored,
            'dropped_candidates' => array_values($droppedCandidates),
            'kept_must_keep' => array_values($keptMustKeep),
            'total_tokens' => $totalTokens,
            'retained_tokens' => $retainedTokens,
            'reasons' => AtlasContextStringListNormalizer::uniqueTrimmedStrings($reasons),
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function intValue(array $segment, string $key): int
    {
        $value = $segment[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function floatValue(array $segment, string $key): float
    {
        $value = $segment[$key] ?? 0.0;

        return is_float($value) || is_int($value) ? (float) $value : (float) $value;
    }
}

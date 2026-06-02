<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * Resolve a binary memory conflict by walking a fixed precedence cascade over
 * three ordered axes: authority, then evidence, then freshness.
 *
 * The resolver is pure: every returned field is computed from the two side
 * payloads via deterministic rules, with no clock, DB, randomness or I/O.
 *
 * Each side payload shape:
 *   label:string, authority_rank:int (0=human/policy best, higher=worse),
 *   evidence_count:int>=0, recorded_ts:int
 */
final class MemoryConflictAxisResolver
{
    private const SCHEMA_VERSION = 'atlas.memory.conflict_axis_resolution.v1';

    private const AXIS_AUTHORITY = 'authority';

    private const AXIS_EVIDENCE = 'evidence';

    private const AXIS_FRESHNESS = 'freshness';

    private const DECISIVE_NONE = 'none';

    private const WINNER_TIE = 'tie';

    /**
     * Cascade order: authority dominates evidence which dominates freshness.
     *
     * @var list<string>
     */
    private const AXIS_ORDER = [
        self::AXIS_AUTHORITY,
        self::AXIS_EVIDENCE,
        self::AXIS_FRESHNESS,
    ];

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return array{
     *     schema_version: string,
     *     winner: string,
     *     axis_scores: array{
     *         a: array{authority: int, evidence: int, freshness: int},
     *         b: array{authority: int, evidence: int, freshness: int}
     *     },
     *     decisive_axis: string,
     *     margin: int,
     *     reasons: list<string>
     * }
     */
    public function resolve(array $a, array $b): array
    {
        $labelA = $this->stringValue($a, 'label', 'a');
        $labelB = $this->stringValue($b, 'label', 'b');

        $authorityA = $this->intValue($a, 'authority_rank');
        $authorityB = $this->intValue($b, 'authority_rank');

        // evidence_count is contractually >= 0.
        $evidenceA = max(0, $this->intValue($a, 'evidence_count'));
        $evidenceB = max(0, $this->intValue($b, 'evidence_count'));

        $freshnessA = $this->intValue($a, 'recorded_ts');
        $freshnessB = $this->intValue($b, 'recorded_ts');

        // R5: per-side normalized flags in {-1, 0, 1}.
        // Lower authority_rank wins, so the flag favours the SMALLER value.
        $authorityFlagA = $this->flag($authorityB, $authorityA);
        $authorityFlagB = $this->flag($authorityA, $authorityB);

        // Higher evidence_count wins, so the flag favours the LARGER value.
        $evidenceFlagA = $this->flag($evidenceA, $evidenceB);
        $evidenceFlagB = $this->flag($evidenceB, $evidenceA);

        // Newer (higher) recorded_ts wins, so the flag favours the LARGER value.
        $freshnessFlagA = $this->flag($freshnessA, $freshnessB);
        $freshnessFlagB = $this->flag($freshnessB, $freshnessA);

        $axisScores = [
            'a' => [
                self::AXIS_AUTHORITY => $authorityFlagA,
                self::AXIS_EVIDENCE => $evidenceFlagA,
                self::AXIS_FRESHNESS => $freshnessFlagA,
            ],
            'b' => [
                self::AXIS_AUTHORITY => $authorityFlagB,
                self::AXIS_EVIDENCE => $evidenceFlagB,
                self::AXIS_FRESHNESS => $freshnessFlagB,
            ],
        ];

        // Precedence cascade R1 -> R4.
        $decisiveAxis = self::DECISIVE_NONE;
        $winner = self::WINNER_TIE;
        $margin = 0;

        if ($authorityA !== $authorityB) {
            // R1: lower authority_rank wins outright.
            $decisiveAxis = self::AXIS_AUTHORITY;
            $winner = $authorityA < $authorityB ? $labelA : $labelB;
            $margin = $this->absGap($authorityA, $authorityB);
        } elseif ($evidenceA !== $evidenceB) {
            // R2: authority tie -> higher evidence_count wins.
            $decisiveAxis = self::AXIS_EVIDENCE;
            $winner = $evidenceA > $evidenceB ? $labelA : $labelB;
            $margin = $this->absGap($evidenceA, $evidenceB);
        } elseif ($freshnessA !== $freshnessB) {
            // R3: authority + evidence tie -> strictly-newer recorded_ts wins.
            $decisiveAxis = self::AXIS_FRESHNESS;
            $winner = $freshnessA > $freshnessB ? $labelA : $labelB;
            $margin = $this->absGap($freshnessA, $freshnessB);
        }
        // else R4: full tie -> winner='tie', decisive_axis='none', margin=0.

        // R6: margin is the absolute gap on the decisive axis only (0 when none).
        // R7: reasons name the decisive axis first, then the non-deciding axes.
        $reasons = $this->buildReasons($decisiveAxis);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'winner' => $winner,
            'axis_scores' => $axisScores,
            'decisive_axis' => $decisiveAxis,
            'margin' => $margin,
            'reasons' => $reasons,
        ];
    }

    /**
     * R7: the decisive axis is named first, then every axis that did NOT decide
     * (in the stable cascade order). When nothing decided, the synthetic
     * 'none' marker leads and all three axes follow as non-deciding.
     *
     * @return list<string>
     */
    private function buildReasons(string $decisiveAxis): array
    {
        $reasons = ['decisive:'.$decisiveAxis];

        foreach (self::AXIS_ORDER as $axis) {
            if ($axis === $decisiveAxis) {
                continue;
            }

            $reasons[] = 'non_deciding:'.$axis;
        }

        return $reasons;
    }

    /**
     * R6: absolute integer gap between two axis values.
     *
     * PHP promotes an integer subtraction to float once the result leaves the
     * native int range, which would silently break the margin:int contract for
     * authority_rank / recorded_ts pairs that straddle PHP_INT_MIN..PHP_INT_MAX.
     * Subtract larger-minus-smaller (so same-sign pairs never overflow) and, for
     * the genuinely unrepresentable cross-range gap, saturate to PHP_INT_MAX —
     * the largest gap an int can hold — keeping margin a non-negative int.
     */
    private function absGap(int $x, int $y): int
    {
        $hi = $x >= $y ? $x : $y;
        $lo = $x >= $y ? $y : $x;

        $gap = $hi - $lo;

        if (is_int($gap)) {
            return $gap;
        }

        return PHP_INT_MAX;
    }

    /**
     * Per-side flag: 1 when $self strictly beats $other on the favoured
     * direction already encoded by the caller, -1 when it strictly loses,
     * 0 on a tie.
     */
    private function flag(int $self, int $other): int
    {
        if ($self > $other) {
            return 1;
        }

        if ($self < $other) {
            return -1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function intValue(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || is_string($value) || is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stringValue(array $payload, string $key, string $default): string
    {
        $value = $payload[$key] ?? $default;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }
}

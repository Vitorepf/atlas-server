<?php

declare(strict_types=1);

namespace App\Services\Ai\Evidence;

/**
 * Kind-weighted evidence strength scorer.
 *
 * Upgrades the legacy count-only heuristic (4 cheap doc links == 'strong') into a
 * weighted total where a single green gate_run or test_result outweighs several
 * doc links. Pure: every returned field is computed from the supplied refs.
 */
final class EvidenceStrengthScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.evidence_strength.v1';

    private const UNKNOWN_KIND = 'unknown';

    /**
     * Weight contributed to the strength total by each normalized evidence kind.
     * Kinds absent from this map contribute 0 (still counted in the breakdown).
     *
     * @var array<string,int>
     */
    private const KIND_WEIGHTS = [
        'doc' => 1,
        'receipt' => 2,
        'commit_hash' => 3,
        'benchmark_weight' => 4,
        'gate_run' => 5,
        'test_result' => 5,
    ];

    /**
     * @param  array<int|string,mixed>  $refs
     * @return array{schema_version:string, score:int, tier:string, kind_breakdown:array<string,int>}
     */
    public function score(array $refs): array
    {
        $total = 0;
        $kindBreakdown = [];

        foreach ($refs as $ref) {
            $kind = $this->normalizeKind($ref);

            if ($kind === null) {
                continue;
            }

            $total += self::KIND_WEIGHTS[$kind] ?? 0;
            $kindBreakdown[$kind] = ($kindBreakdown[$kind] ?? 0) + 1;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $total,
            'tier' => $this->tierForScore($total),
            'kind_breakdown' => $kindBreakdown,
        ];
    }

    /**
     * Resolve the normalized kind for one ref, or null when the entry must be skipped.
     */
    private function normalizeKind(mixed $ref): ?string
    {
        if (is_string($ref)) {
            $trimmed = trim($ref);

            if ($trimmed === '') {
                return null;
            }

            return self::UNKNOWN_KIND;
        }

        if (is_array($ref)) {
            $raw = $ref['kind'] ?? $ref['type'] ?? null;

            if (! is_string($raw)) {
                return self::UNKNOWN_KIND;
            }

            $trimmed = trim($raw);

            return $trimmed === '' ? self::UNKNOWN_KIND : $trimmed;
        }

        return null;
    }

    private function tierForScore(int $score): string
    {
        return match (true) {
            $score <= 0 => 'invalid',
            $score <= 2 => 'weak',
            $score <= 6 => 'moderate',
            default => 'strong',
        };
    }
}

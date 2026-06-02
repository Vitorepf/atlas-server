<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S110 — L8 Frame Replay Plan Builder.
 *
 * Builds the deterministic replay plan that a structural frame-change proposal
 * must clear before it can be promoted. A frame redesign rewrites canonical
 * layers, so it cannot ride the normal feature path: it must be replayed across
 * a broad base of REAL Obras and show zero regression first.
 *
 * Canonical gate (atlas-architecture-evolution-proposal-runtime.md:312):
 *   replay_obras_count_min: 100
 *   replay_regression_observed_count_max: 0
 *
 * Pure: every returned field is computed from build() inputs. No I/O, DB,
 * Eloquent, facades, provider/HTTP, git/process, filesystem, clock, randomness.
 */
final class L8FrameReplayPlanBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.frame_replay_plan.v1';

    /**
     * Minimum count of distinct real Obras that must be replayed before a
     * structural frame change may be promoted.
     */
    private const MIN_REAL_OBRAS = 100;

    /**
     * Maximum regression observations tolerated in the replay (measured-or-reverted).
     */
    private const MAX_REGRESSIONS = 0;

    /**
     * @param array<string,mixed> $proposal
     * @param array<int|string,mixed> $obraRefs
     *
     * @return array{
     *     schema_version: string,
     *     replay_ready: bool,
     *     replay_plan: array{
     *         obras_replayed_count: int,
     *         diversity_buckets: list<string>,
     *         regression_metrics: array{
     *             regression_observed_count: int,
     *             clean_replay_count: int,
     *             regression_rate: float
     *         }
     *     },
     *     real_obra_refs: list<string>,
     *     min_real_obras: int,
     *     blockers: list<string>
     * }
     */
    public function build(array $proposal, array $obraRefs): array
    {
        $realObraIds = $this->realObraIds($obraRefs);
        $replayedCount = count($realObraIds);

        $regressionObservedCount = $this->regressionObservedCount($obraRefs, $realObraIds);
        $cleanReplayCount = max($replayedCount - $regressionObservedCount, 0);
        $regressionRate = $replayedCount > 0
            ? $this->clampUnit($regressionObservedCount / $replayedCount)
            : 0.0;

        $blockers = [];

        if (! $this->hasProposalId($proposal)) {
            $blockers[] = 'proposal_id_missing';
        }

        if (count($obraRefs) > 0 && $replayedCount === 0) {
            $blockers[] = 'synthetic_only_replay_blocked';
        }

        if ($replayedCount < self::MIN_REAL_OBRAS) {
            $blockers[] = 'insufficient_real_obras';
        }

        if ($regressionObservedCount > self::MAX_REGRESSIONS) {
            $blockers[] = 'regressions_observed';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'replay_ready' => $blockers === [],
            'replay_plan' => [
                'obras_replayed_count' => $replayedCount,
                'diversity_buckets' => $this->diversityBuckets($obraRefs, $realObraIds),
                'regression_metrics' => [
                    'regression_observed_count' => $regressionObservedCount,
                    'clean_replay_count' => $cleanReplayCount,
                    'regression_rate' => $regressionRate,
                ],
            ],
            'real_obra_refs' => $realObraIds,
            'min_real_obras' => self::MIN_REAL_OBRAS,
            'blockers' => $blockers,
        ];
    }

    /**
     * Distinct ids of the real (non-synthetic) Obras, sorted and re-indexed so
     * the contract stays list<string> (never int-key coerced).
     *
     * @param array<int|string,mixed> $obraRefs
     *
     * @return list<string>
     */
    private function realObraIds(array $obraRefs): array
    {
        $ids = [];

        foreach ($obraRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $id = $this->obraId($ref);

            if ($id === '' || $this->isSynthetic($ref, $id)) {
                continue;
            }

            $ids[$id] = true;
        }

        return $this->sortedStringList(array_keys($ids));
    }

    /**
     * Distinct diversity bucket labels drawn from the real Obras only, sorted
     * and re-indexed to honour the list<string> contract.
     *
     * @param array<int|string,mixed> $obraRefs
     * @param list<string> $realObraIds
     *
     * @return list<string>
     */
    private function diversityBuckets(array $obraRefs, array $realObraIds): array
    {
        $realLookup = array_fill_keys($realObraIds, true);
        $buckets = [];

        foreach ($obraRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $id = $this->obraId($ref);

            if ($id === '' || ! isset($realLookup[$id])) {
                continue;
            }

            $bucket = $this->bucketLabel($ref);

            if ($bucket !== '') {
                $buckets[$bucket] = true;
            }
        }

        return $this->sortedStringList(array_keys($buckets));
    }

    /**
     * Normalise a set of assoc-array keys into a sorted, zero-indexed
     * list<string>. PHP coerces integer-like string keys back to int when they
     * are used as array keys, so every key is cast to string here to keep the
     * list<string> contract intact for numeric Obra ids / bucket labels.
     *
     * @param list<int|string> $keys
     *
     * @return list<string>
     */
    private function sortedStringList(array $keys): array
    {
        $values = array_map(static fn (int|string $key): string => (string) $key, $keys);
        sort($values, SORT_STRING);

        return array_values($values);
    }

    /**
     * @param array<int|string,mixed> $obraRefs
     * @param list<string> $realObraIds
     */
    private function regressionObservedCount(array $obraRefs, array $realObraIds): int
    {
        $realLookup = array_fill_keys($realObraIds, true);
        $regressed = [];

        foreach ($obraRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $id = $this->obraId($ref);

            if ($id === '' || ! isset($realLookup[$id])) {
                continue;
            }

            if ($this->isRegression($ref)) {
                $regressed[$id] = true;
            }
        }

        return count($regressed);
    }

    /**
     * @param array<string,mixed> $proposal
     */
    private function hasProposalId(array $proposal): bool
    {
        return $this->stringValue($proposal, 'proposal_id') !== '';
    }

    /**
     * @param array<int|string,mixed> $ref
     */
    private function obraId(array $ref): string
    {
        $id = $this->stringValue($ref, 'obra_id');

        if ($id !== '') {
            return $id;
        }

        return $this->stringValue($ref, 'id');
    }

    /**
     * An Obra ref is synthetic when it self-declares synthetic, is sourced from a
     * synthetic/fixture origin, or carries a synthetic/fixture id prefix.
     *
     * @param array<int|string,mixed> $ref
     */
    private function isSynthetic(array $ref, string $id): bool
    {
        if (($ref['synthetic'] ?? false) === true) {
            return true;
        }

        if (($ref['real'] ?? true) === false) {
            return true;
        }

        $source = strtolower($this->stringValue($ref, 'source'));

        if ($source === 'synthetic' || $source === 'fixture' || $source === 'stub') {
            return true;
        }

        $lowerId = strtolower($id);

        foreach (['synthetic:', 'synthetic-', 'fixture:', 'fixture-', 'stub:'] as $prefix) {
            if (str_starts_with($lowerId, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string,mixed> $ref
     */
    private function isRegression(array $ref): bool
    {
        if (($ref['regression'] ?? false) === true) {
            return true;
        }

        return strtolower($this->stringValue($ref, 'status')) === 'regressed';
    }

    /**
     * @param array<int|string,mixed> $ref
     */
    private function bucketLabel(array $ref): string
    {
        foreach (['bucket', 'diversity_bucket', 'domain', 'area'] as $key) {
            $value = $this->stringValue($ref, $key);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int|string,mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    private function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}

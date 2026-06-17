<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * Extracted dense-cluster helpers for ContextWindowMustKeepBudgetAllocator.
 *
 * Pure deterministic helpers: each public method mirrors, byte-for-byte, a
 * block of logic that previously bloated ContextWindowMustKeepBudgetAllocator::
 * allocate (cyclomatic 19). Moving these blocks here is what drives the
 * worst-method cyclomatic count of `allocate` strictly lower in place.
 *
 * No clock, no I/O, no DB, no facades, no randomness — every field is computed
 * from the method inputs via the same ranking + compression math the in-line
 * blocks performed.
 */
final class ContextWindowMustKeepBudgetAllocatorSupport
{
    /**
     * Phase 2: per-segment compression targets for must_keep segments that did
     * not fit full in Phase 1.
     *
     * Mirrors the in-line Phase 2 logic of
     * ContextWindowMustKeepBudgetAllocator::allocate byte-for-byte: same
     * flagged-tokens total, same leftover budget, same flooring-leftover
     * adjustment for higher-ranked flagged segments, same unrecoverable
     * overflow semantics, same fit-invariant guard, same row schemas.
     *
     * @param  list<array<string,mixed>>  $flagged
     * @return array{included: list<array<string,mixed>>, excluded: list<array<string,mixed>>, assigned_compression_tokens: int, unrecoverable: bool}
     */
    public function assignCompressionTargets(array $flagged, int $budget, int $usedFull): array
    {
        $flaggedTokensTotal = 0;
        foreach ($flagged as $segment) {
            $flaggedTokensTotal += $segment['tokens'];
        }

        $leftoverForCompression = max(0, $budget - $usedFull);
        $unrecoverable = false;
        $assignedCompressionTokens = 0;
        $included = [];
        $excluded = [];

        foreach ($flagged as $position => $segment) {
            $target = $this->compressionTarget(
                $segment['tokens'],
                $flaggedTokensTotal,
                $leftoverForCompression,
            );

            // Give flooring leftovers to higher-ranked flagged must_keep before optional context.
            if ($assignedCompressionTokens + $target < $leftoverForCompression && $target < $segment['tokens']) {
                $target++;
            }

            if ($target <= 0) {
                $unrecoverable = true;
                $excluded[] = $this->excludedRow(
                    $segment,
                    'overflow',
                    'must_keep_unrecoverable_overflow',
                );

                continue;
            }

            // Guard the fit invariant: never let assigned targets exceed leftover.
            if ($assignedCompressionTokens + $target > $leftoverForCompression) {
                $target = $leftoverForCompression - $assignedCompressionTokens;
            }

            if ($target <= 0) {
                $unrecoverable = true;
                $excluded[] = $this->excludedRow(
                    $segment,
                    'overflow',
                    'must_keep_unrecoverable_overflow',
                );

                continue;
            }

            $assignedCompressionTokens += $target;
            $included[] = $this->includedRow(
                $segment,
                'flagged_for_compression',
                $target,
                'must_keep_overflow_compressed_to_fit',
            );
        }

        return [
            'included' => $included,
            'excluded' => $excluded,
            'assigned_compression_tokens' => $assignedCompressionTokens,
            'unrecoverable' => $unrecoverable,
        ];
    }

    /**
     * Phase 3: fit optional (non-must_keep) segments only with the leftover
     * budget remaining after the must_keep plan.
     *
     * Mirrors the in-line Phase 3 logic of
     * ContextWindowMustKeepBudgetAllocator::allocate byte-for-byte: same rank
     * pass, same greedy fit, same excluded rows.
     *
     * @param  list<array<string,mixed>>  $optionalRanked
     * @return array{included: list<array<string,mixed>>, excluded: list<array<string,mixed>>, used_after_must_keep: int}
     */
    public function fitOptionalWithinLeftover(array $optionalRanked, int $budget, int $usedAfterMustKeep): array
    {
        $used = $usedAfterMustKeep;
        $included = [];
        $excluded = [];

        foreach ($optionalRanked as $segment) {
            if ($used + $segment['tokens'] <= $budget) {
                $used += $segment['tokens'];
                $included[] = $this->includedRow(
                    $segment,
                    'kept_full',
                    $segment['tokens'],
                    'optional_within_leftover_budget',
                );

                continue;
            }

            $excluded[] = $this->excludedRow(
                $segment,
                'budget_trim_optional',
                'optional_trimmed_no_leftover_budget',
            );
        }

        return [
            'included' => $included,
            'excluded' => $excluded,
            'used_after_must_keep' => $used,
        ];
    }

    /**
     * Compute the must_keep coverage ratio and the final blockers / degradation
     * flag set.
     *
     * Mirrors the in-line tail-block of
     * ContextWindowMustKeepBudgetAllocator::allocate byte-for-byte: same
     * coverage formula (keptFullCount / mustKeepCount, rounded to 4, 1.0 when
     * zero must_keep), same three blocker conditions, same degradation flag.
     *
     * @param  list<array<string,mixed>>  $flagged
     * @return array{coverage: float, blockers: list<string>, degradation_required: bool}
     */
    public function buildCoverageAndBlockers(int $keptFullCount, int $mustKeepCount, array $flagged, bool $overflow, bool $unrecoverable): array
    {
        $coverage = $mustKeepCount === 0
            ? 1.0
            : round($keptFullCount / $mustKeepCount, 4);

        $blockers = [];

        if ($coverage < 1.0) {
            $blockers[] = 'must_keep_coverage_below_one';
        }

        if ($flagged !== []) {
            $blockers[] = 'must_keep_overflow_requires_compression';
        }

        if ($unrecoverable) {
            $blockers[] = 'must_keep_unrecoverable_overflow';
        }

        $degradationRequired = $overflow || $coverage < 1.0;

        return [
            'coverage' => $coverage,
            'blockers' => $blockers,
            'degradation_required' => $degradationRequired,
        ];
    }

    /**
     * Split normalized segments into must_keep vs optional and rank each list
     * using the same ordering the in-line Phase 1 / Phase 3 blocks applied.
     *
     * Mirrors the in-line split+rank block of
     * ContextWindowMustKeepBudgetAllocator::allocate byte-for-byte: same two
     * array_filter closures, same rankMustKeep (category_rank asc, priority
     * desc, tokens asc, index asc) and rankOptional (priority desc, tokens
     * asc, index asc) orderings.
     *
     * @param  list<array<string,mixed>>  $normalized
     * @return array{mustKeepRanked: list<array<string,mixed>>, optionalRanked: list<array<string,mixed>>, mustKeepCount: int}
     */
    public function splitAndRankSegments(array $normalized): array
    {
        $mustKeep = array_values(array_filter($normalized, static fn (array $s): bool => $s['must_keep']));
        $optional = array_values(array_filter($normalized, static fn (array $s): bool => ! $s['must_keep']));

        return [
            'mustKeepRanked' => $this->rankMustKeep($mustKeep),
            'optionalRanked' => $this->rankOptional($optional),
            'mustKeepCount' => count($mustKeep),
        ];
    }

    /**
     * Phase 1: greedily fit must_keep segments full, in rank order. Computes
     * the kept_full rows, the flagged list (segments that did not fit full),
     * the total must_keep token count, the deficit versus the budget, and the
     * overflow flag — exactly the in-line block of
     * ContextWindowMustKeepBudgetAllocator::allocate, relocated.
     *
     * @param  list<array<string,mixed>>  $mustKeepRanked
     * @return array{
     *     included: list<array<string,mixed>>,
     *     flagged: list<array<string,mixed>>,
     *     usedFull: int,
     *     keptFullCount: int,
     *     mustKeepTokensTotal: int,
     *     deficitTokens: int,
     *     overflow: bool
     * }
     */
    public function planMustKeepGreedyFit(array $mustKeepRanked, int $budget): array
    {
        $mustKeepTokensTotal = 0;
        foreach ($mustKeepRanked as $segment) {
            $mustKeepTokensTotal += $segment['tokens'];
        }

        $deficitTokens = max(0, $mustKeepTokensTotal - $budget);
        $overflow = $deficitTokens > 0;

        $included = [];
        $flagged = [];
        $usedFull = 0;
        $keptFullCount = 0;

        foreach ($mustKeepRanked as $segment) {
            if ($usedFull + $segment['tokens'] <= $budget) {
                $usedFull += $segment['tokens'];
                $keptFullCount++;
                $included[] = $this->includedRow(
                    $segment,
                    'kept_full',
                    $segment['tokens'],
                    'must_keep_fits_full_budget',
                );

                continue;
            }

            $flagged[] = $segment;
        }

        return [
            'included' => $included,
            'flagged' => $flagged,
            'usedFull' => $usedFull,
            'keptFullCount' => $keptFullCount,
            'mustKeepTokensTotal' => $mustKeepTokensTotal,
            'deficitTokens' => $deficitTokens,
            'overflow' => $overflow,
        ];
    }

    /**
     * Rank must_keep DESC by (category_rank asc, priority desc, tokens asc tiebreak),
     * stable on original order for full ties.
     *
     * @param  list<array<string,mixed>>  $mustKeep
     * @return list<array<string,mixed>>
     */
    private function rankMustKeep(array $mustKeep): array
    {
        usort($mustKeep, static function (array $a, array $b): int {
            return [(int) $a['category_rank'], -(float) $a['priority'], (int) $a['tokens'], (int) $a['index']]
                <=> [(int) $b['category_rank'], -(float) $b['priority'], (int) $b['tokens'], (int) $b['index']];
        });

        return $mustKeep;
    }

    /**
     * Rank optional segments by priority desc, tokens asc, stable on original order.
     *
     * @param  list<array<string,mixed>>  $optional
     * @return list<array<string,mixed>>
     */
    private function rankOptional(array $optional): array
    {
        usort($optional, static function (array $a, array $b): int {
            return [-(float) $a['priority'], (int) $a['tokens'], (int) $a['index']]
                <=> [-(float) $b['priority'], (int) $b['tokens'], (int) $b['index']];
        });

        return $optional;
    }

    /**
     * Per-segment compression target: scale the segment down by the share of the
     * leftover budget proportional to its token weight among flagged segments.
     * Floored so the assigned plan never exceeds the leftover budget.
     */
    private function compressionTarget(int $tokens, int $flaggedTokensTotal, int $leftover): int
    {
        if ($leftover <= 0 || $flaggedTokensTotal <= 0 || $tokens <= 0) {
            return 0;
        }

        $target = (int) floor(($tokens * $leftover) / $flaggedTokensTotal);

        return min($target, $tokens);
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array{ref:string,kind:string,category:string,tokens:int,priority:float,must_keep:bool,disposition:string,compression_target_tokens:int,reason:string}
     */
    private function includedRow(array $segment, string $disposition, int $compressionTarget, string $reason): array
    {
        return [
            'ref' => (string) $segment['ref'],
            'kind' => (string) $segment['kind'],
            'category' => (string) $segment['category'],
            'tokens' => (int) $segment['tokens'],
            'priority' => (float) $segment['priority'],
            'must_keep' => (bool) $segment['must_keep'],
            'disposition' => $disposition,
            'compression_target_tokens' => $compressionTarget,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array{ref:string,kind:string,tokens:int,must_keep:bool,disposition:string,reason:string}
     */
    private function excludedRow(array $segment, string $disposition, string $reason): array
    {
        return [
            'ref' => (string) $segment['ref'],
            'kind' => (string) $segment['kind'],
            'tokens' => (int) $segment['tokens'],
            'must_keep' => (bool) $segment['must_keep'],
            'disposition' => $disposition,
            'reason' => $reason,
        ];
    }
}

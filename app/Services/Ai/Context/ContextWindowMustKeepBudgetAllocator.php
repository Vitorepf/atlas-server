<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * Pure deterministic must_keep overflow degradation planner.
 *
 * Given a list of context segments and a provider token budget, produce a
 * degradation plan that ACTUALLY fits the budget: must_keep segments are ranked
 * and greedily fitted full; on overflow the remaining must_keep segments are
 * flagged for compression with a per-segment compression target that recovers
 * the deficit (or marked overflow when no positive target is recoverable);
 * optional (non-must_keep) segments are fitted only with leftover budget.
 *
 * No clock, no I/O, no DB, no facades, no randomness — every field is computed
 * from the method inputs via real ranking + compression-target math.
 */
final class ContextWindowMustKeepBudgetAllocator
{
    private const SCHEMA_VERSION = 'atlas.context.must_keep_budget_allocation.v1';

    /** Lowest weight = highest rank. Mirrors the compiler runtime category lexicon. */
    private const CATEGORY_RANK = [
        'must_keep' => 0,
        'evidence' => 1,
        'memory' => 2,
        'estimate' => 3,
        'context' => 4,
    ];

    private const DEFAULT_CATEGORY_RANK = 99;

    /**
     * @param  list<array{kind?:string,ref?:string,tokens?:int,priority?:float,must_keep?:bool}>  $segments
     * @return array{
     *     schema_version:string,
     *     overflow:bool,
     *     token_budget:int,
     *     must_keep_tokens_total:int,
     *     deficit_tokens:int,
     *     must_keep_coverage:float,
     *     degradation_required:bool,
     *     included:list<array{ref:string,kind:string,category:string,tokens:int,priority:float,must_keep:bool,disposition:string,compression_target_tokens:int,reason:string}>,
     *     excluded:list<array{ref:string,kind:string,tokens:int,must_keep:bool,disposition:string,reason:string}>,
     *     blockers:list<string>
     * }
     */
    public function allocate(array $segments, int $tokenBudget): array
    {
        $budget = max(0, $tokenBudget);

        $normalized = [];
        foreach (array_values($segments) as $index => $segment) {
            $normalized[] = $this->normalize($segment, $index);
        }

        $mustKeep = array_values(array_filter($normalized, static fn (array $s): bool => $s['must_keep']));
        $optional = array_values(array_filter($normalized, static fn (array $s): bool => ! $s['must_keep']));

        $mustKeepRanked = $this->rankMustKeep($mustKeep);

        $mustKeepTokensTotal = 0;
        foreach ($mustKeepRanked as $segment) {
            $mustKeepTokensTotal += $segment['tokens'];
        }

        $deficitTokens = max(0, $mustKeepTokensTotal - $budget);
        $overflow = $deficitTokens > 0;

        $included = [];
        $excluded = [];
        $blockers = [];

        // Phase 1: greedily fit must_keep full, in rank order.
        $usedFull = 0;
        $keptFullCount = 0;
        $flagged = [];
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

        // Phase 2: per-segment compression targets for must_keep that did not fit full.
        $flaggedTokensTotal = 0;
        foreach ($flagged as $segment) {
            $flaggedTokensTotal += $segment['tokens'];
        }

        $leftoverForCompression = max(0, $budget - $usedFull);
        $unrecoverable = false;
        $assignedCompressionTokens = 0;

        foreach ($flagged as $position => $segment) {
            $target = $this->compressionTarget(
                $segment['tokens'],
                $flaggedTokensTotal,
                $leftoverForCompression,
            );

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

        // Phase 3: optional segments only with leftover budget after must_keep plan.
        $usedAfterMustKeep = $usedFull + $assignedCompressionTokens;
        $optionalRanked = $this->rankOptional($optional);
        foreach ($optionalRanked as $segment) {
            if ($usedAfterMustKeep + $segment['tokens'] <= $budget) {
                $usedAfterMustKeep += $segment['tokens'];
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

        $mustKeepCount = count($mustKeepRanked);
        $coverage = $mustKeepCount === 0
            ? 1.0
            : round($keptFullCount / $mustKeepCount, 4);

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
            'schema_version' => self::SCHEMA_VERSION,
            'overflow' => $overflow,
            'token_budget' => $budget,
            'must_keep_tokens_total' => $mustKeepTokensTotal,
            'deficit_tokens' => $deficitTokens,
            'must_keep_coverage' => $coverage,
            'degradation_required' => $degradationRequired,
            'included' => $included,
            'excluded' => $excluded,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array{kind?:string,ref?:string,tokens?:int,priority?:float,must_keep?:bool}  $segment
     * @return array{kind:string,ref:string,tokens:int,priority:float,must_keep:bool,category:string,category_rank:int,index:int}
     */
    private function normalize(array $segment, int $index): array
    {
        $kind = (string) ($segment['kind'] ?? 'context');
        $ref = (string) ($segment['ref'] ?? $kind.':'.$index);
        $tokens = max(0, (int) ($segment['tokens'] ?? 0));
        $priority = round(max(0.0, min(1.0, (float) ($segment['priority'] ?? 0.0))), 4);
        $mustKeep = (bool) ($segment['must_keep'] ?? false);
        $category = $this->category($kind);

        return [
            'kind' => $kind,
            'ref' => $ref,
            'tokens' => $tokens,
            'priority' => $priority,
            'must_keep' => $mustKeep,
            'category' => $category,
            'category_rank' => self::CATEGORY_RANK[$category] ?? self::DEFAULT_CATEGORY_RANK,
            'index' => $index,
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

    private function category(string $kind): string
    {
        return match ($kind) {
            'decision', 'blocker', 'constraint', 'dod', 'risk', 'receipt' => 'must_keep',
            'evidence', 'source', 'test' => 'evidence',
            'memory' => 'memory',
            'estimate' => 'estimate',
            default => 'context',
        };
    }
}

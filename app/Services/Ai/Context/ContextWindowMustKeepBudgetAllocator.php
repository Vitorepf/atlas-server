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
 *
 * Complexity of `allocate` is kept strictly lower than the previous in-line
 * implementation by delegating the dense phases (split+rank, Phase 1 greedy
 * fit, Phase 2 compression-target plan, Phase 3 optional fit, coverage +
 * blockers tail) to ContextWindowMustKeepBudgetAllocatorSupport. Public API
 * and result schema are unchanged.
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

    private ContextWindowMustKeepBudgetAllocatorSupport $support;

    public function __construct(?ContextWindowMustKeepBudgetAllocatorSupport $support = null)
    {
        $this->support = $support ?? new ContextWindowMustKeepBudgetAllocatorSupport();
    }

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

        $normalized = $this->normalizeAll($segments);
        $split = $this->support->splitAndRankSegments($normalized);
        $phase1 = $this->support->planMustKeepGreedyFit($split['mustKeepRanked'], $budget);
        $phase2 = $this->support->assignCompressionTargets($phase1['flagged'], $budget, $phase1['usedFull']);
        $phase3 = $this->support->fitOptionalWithinLeftover(
            $split['optionalRanked'],
            $budget,
            $phase1['usedFull'] + $phase2['assigned_compression_tokens'],
        );
        $tail = $this->support->buildCoverageAndBlockers(
            $phase1['keptFullCount'],
            $split['mustKeepCount'],
            $phase1['flagged'],
            $phase1['overflow'],
            $phase2['unrecoverable'],
        );

        $included = array_merge($phase1['included'], $phase2['included'], $phase3['included']);
        $excluded = array_merge($phase2['excluded'], $phase3['excluded']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'overflow' => $phase1['overflow'],
            'token_budget' => $budget,
            'must_keep_tokens_total' => $phase1['mustKeepTokensTotal'],
            'deficit_tokens' => $phase1['deficitTokens'],
            'must_keep_coverage' => $tail['coverage'],
            'degradation_required' => $tail['degradation_required'],
            'included' => $included,
            'excluded' => $excluded,
            'blockers' => $tail['blockers'],
        ];
    }

    /**
     * @param  list<array{kind?:string,ref?:string,tokens?:int,priority?:float,must_keep?:bool}>  $segments
     * @return list<array<string,mixed>>
     */
    private function normalizeAll(array $segments): array
    {
        $normalized = [];
        foreach (array_values($segments) as $index => $segment) {
            $normalized[] = $this->normalize($segment, $index);
        }

        return $normalized;
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
        $category = $this->category($kind);
        $priority = round(max(0.0, min(1.0, (float) ($segment['priority'] ?? 0.0))), 4);
        $mustKeep = (bool) ($segment['must_keep'] ?? ($category === 'must_keep'));

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

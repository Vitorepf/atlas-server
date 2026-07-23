<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · W-11 — Staged, budgeted plan for the FIRST index of an external repo.
 *
 * Onboarding a large unknown repository is the moment the code graph is most at
 * risk of blowing its budget: a naive "index everything" pass on a 200k-file
 * monorepo melts the [py] parse stage and produces a graph nobody asked for. This
 * planner is the [php] orchestration that decides — BEFORE the heavy parse runs —
 * whether the first index can be done in full or must be STAGED into prioritised,
 * capped tiers whose file budget is provably bounded.
 *
 * The contract, in order of priority:
 *
 *   - SMALL repo (fits the budget): strategy 'full', not sampled, a single stage
 *     that covers every file. Nothing is left out; the budget was never at risk.
 *   - LARGE repo (over the file budget, OR so byte-heavy that even a sub-budget
 *     file count would overrun the parse): strategy 'staged', sampled, three
 *     prioritised tiers —
 *       tier 1  entrypoints/top-level   (small, fixed-fraction cap — index the
 *                                        repo's spine first so the graph is useful
 *                                        immediately)
 *       tier 2  core                    (the bulk of the budget — the modules that
 *                                        carry the real structure)
 *       tier 3  sampled remainder       (whatever budget is left, a representative
 *                                        sample of the long tail — recall without
 *                                        paying for the whole tail)
 *     with the HARD invariant  Σ stage.max_files ≤ budget_files  always.
 *
 * Determinism & fail-safety (house contract, mirrors {@see CodeGraphInferredGuard}):
 *   - Pure function of its arguments. No DB, no clock, no random, no provider, no
 *     filesystem. Same (totalFiles, estimatedBytes, opts) → byte-identical output.
 *   - Never throws. Negative / zero / NaN-ish inputs are clamped to a safe floor;
 *     a zero-file repo yields a valid 'full' plan with an empty stage list.
 *   - Tier caps are computed by integer arithmetic with a remainder-aware fill so
 *     the three caps SUM to exactly the planned budget (never one over) regardless
 *     of rounding — the budget ceiling can never be silently exceeded.
 *   - Config is read with inline default literals so it works with no config edit;
 *     `$opts` overrides config per call. Budget/batch are clamped to sane minimums.
 *
 * This is [php] by the runtime-language boundary: it PLANS the index (a bounded
 * decision/orchestration). The heavy parse the plan governs is [py].
 */
class CodeGraphFirstIndexPlanner
{
    public const SCHEMA = 'atlas.code_graph.first_index_planner.v1';

    public const STRATEGY_FULL = 'full';

    public const STRATEGY_STAGED = 'staged';

    /**
     * Lowest budget we will ever plan for. A misconfigured 0/negative budget would
     * otherwise produce an unusable empty plan; this floor keeps a plan meaningful.
     */
    private const MIN_BUDGET_FILES = 1;

    /** Lowest batch size. A 0/negative batch would stall the indexer. */
    private const MIN_BATCH_SIZE = 1;

    /**
     * Fraction of the budget given to the tier-1 (entrypoints/top-level) stage.
     * Small on purpose: the spine is cheap and high-value; the bulk belongs to core.
     */
    private const TIER1_FRACTION = 0.10;

    /** Fraction of the budget given to the tier-2 (core) stage. The lion's share. */
    private const TIER2_FRACTION = 0.60;

    /**
     * Bytes-per-file ceiling used to convert the byte estimate into an equivalent
     * file budget. If a repo's average file is heavier than this, its EFFECTIVE
     * file budget shrinks so the parse stage stays bounded by bytes as well as by
     * count. Chosen as a generous source-file average (~50 KiB) so ordinary repos
     * are never penalised; only genuinely byte-heavy trees trip the byte guard.
     */
    private const BYTES_PER_FILE_CEILING = 51200;

    /**
     * Produce the first-index plan.
     *
     * @param  int  $totalFiles  number of candidate files discovered in the repo
     *   (negative/NaN-ish → clamped to 0).
     * @param  int  $estimatedBytes  estimated total bytes of those files
     *   (negative/NaN-ish → clamped to 0). Used only to detect a byte-heavy repo
     *   that should be staged even when its file count fits the budget.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `budget_files` (int): max files the first index may touch (default from
     *     config 'atlas.code_graph.first_index_budget_files', else 5000).
     *   - `batch_size` (int): files per parse batch (default from config
     *     'atlas.code_graph.index_batch_size', else 500).
     * @return array{
     *   strategy: 'full'|'staged',
     *   sampled: bool,
     *   budget_files: int,
     *   batch_size: int,
     *   stages: array<int,array{tier:int,label:string,max_files:int,reason:string}>,
     *   total_files: int
     * }
     *   For 'full': a single stage with max_files == total_files (≤ budget). For
     *   'staged': three tiers whose max_files SUM to the planned budget (≤
     *   budget_files) and whose total never exceeds total_files.
     */
    public function plan(int $totalFiles, int $estimatedBytes, array $opts = []): array
    {
        $totalFiles = $this->clampNonNegative($totalFiles);
        $estimatedBytes = $this->clampNonNegative($estimatedBytes);
        $budgetFiles = $this->resolveBudgetFiles($opts);
        $batchSize = $this->resolveBatchSize($opts);

        // The first index is bounded by TWO ceilings: a file-count budget and an
        // implied byte budget (budgetFiles × an average-file ceiling). A repo is
        // indexed in full only when it fits BOTH; otherwise it is staged. The byte
        // ceiling is what catches a repo whose file count fits but whose bytes would
        // overrun the parse — the effective budget then shrinks to the byte-derived
        // limit so total bytes parsed stays bounded too. Never rises above the file
        // budget, never above the file count.
        $effectiveBudget = $this->effectiveBudget($budgetFiles, $totalFiles, $estimatedBytes);

        if ($totalFiles <= $effectiveBudget) {
            return $this->fullPlan($totalFiles, $budgetFiles, $batchSize);
        }

        return $this->stagedPlan($totalFiles, $effectiveBudget, $budgetFiles, $batchSize);
    }

    /**
     * A FULL plan: index everything in a single stage. Used when the repo fits the
     * (effective) budget. For a zero-file repo this is a valid empty plan — one
     * stage with max_files 0, sampled false — never an error.
     */
    private function fullPlan(int $totalFiles, int $budgetFiles, int $batchSize): array
    {
        return [
            'strategy' => self::STRATEGY_FULL,
            'sampled' => false,
            'budget_files' => $budgetFiles,
            'batch_size' => $batchSize,
            'stages' => [
                [
                    'tier' => 1,
                    'label' => 'all',
                    'max_files' => $totalFiles,
                    'reason' => 'Repository fits the first-index budget; indexed in full in a single pass.',
                ],
            ],
            'total_files' => $totalFiles,
        ];
    }

    /**
     * A STAGED plan: three prioritised, capped tiers whose caps SUM to exactly the
     * planned budget (the smaller of effectiveBudget and totalFiles). Tier sizes are
     * computed by integer math with a remainder-aware fill so the sum is exact —
     * the budget ceiling is never exceeded and no file is double-counted.
     */
    private function stagedPlan(int $totalFiles, int $effectiveBudget, int $budgetFiles, int $batchSize): array
    {
        // Never plan to index more files than exist, and never above the effective
        // budget. This is the number the three tiers must partition exactly.
        $plannedBudget = min($effectiveBudget, $totalFiles);
        if ($plannedBudget < 0) {
            $plannedBudget = 0;
        }

        // Tier caps by fixed fractions, floored. The remainder (budget minus the two
        // floored upper tiers) flows ENTIRELY into the sampled tier-3 so the three
        // caps reconstruct $plannedBudget exactly with no rounding drift.
        $tier1 = (int) floor($plannedBudget * self::TIER1_FRACTION);
        $tier2 = (int) floor($plannedBudget * self::TIER2_FRACTION);

        // Guard against any pathological fraction sum (defensive: current fractions
        // total 0.70 < 1.0, but clamp so tier1+tier2 can never exceed the budget and
        // force tier3 negative).
        if ($tier1 + $tier2 > $plannedBudget) {
            $tier2 = max(0, $plannedBudget - $tier1);
        }

        $tier3 = $plannedBudget - $tier1 - $tier2; // exact remainder, ≥ 0 by construction.

        $remainderTotal = max(0, $totalFiles - $tier1 - $tier2);

        $stages = [
            [
                'tier' => 1,
                'label' => 'entrypoints',
                'max_files' => $tier1,
                'reason' => 'Top-level entrypoints and package roots first: index the repository spine so the graph is useful immediately.',
            ],
            [
                'tier' => 2,
                'label' => 'core',
                'max_files' => $tier2,
                'reason' => 'Core modules carrying the bulk of the structure: the largest share of the budget.',
            ],
            [
                'tier' => 3,
                'label' => 'sampled-remainder',
                'max_files' => $tier3,
                'reason' => sprintf(
                    'Representative sample of the remaining %s files, capped at the leftover budget; the long tail is recalled without paying to parse all of it.',
                    number_format($remainderTotal)
                ),
            ],
        ];

        return [
            'strategy' => self::STRATEGY_STAGED,
            'sampled' => true,
            'budget_files' => $budgetFiles,
            'batch_size' => $batchSize,
            'stages' => $stages,
            'total_files' => $totalFiles,
        ];
    }

    /**
     * The effective (planned) budget: the largest number of files the first index
     * may touch given BOTH ceilings.
     *
     * There is an implied byte budget = budgetFiles × {@see BYTES_PER_FILE_CEILING}
     * (the most bytes the first parse should read). When the repo's estimated bytes
     * exceed that budget, the repo is byte-heavy: its effective budget shrinks to the
     * number of AVERAGE-sized files that fit the byte budget
     * (byteBudget ÷ avgBytesPerFile), so a few enormous files cannot smuggle the
     * parse over its byte ceiling. When the bytes fit the byte budget, the file
     * budget stands. The result is always in [0, budgetFiles].
     *
     * All arithmetic is integer and overflow-guarded: byteBudget is computed only
     * when it cannot overflow PHP_INT_MAX, and the byte-derived file count uses
     * intdiv so a single huge file never produces a nonsensical budget.
     */
    private function effectiveBudget(int $budgetFiles, int $totalFiles, int $estimatedBytes): int
    {
        // No byte signal, or an empty repo → the file budget is the only ceiling.
        if ($estimatedBytes <= 0 || $totalFiles <= 0) {
            return $budgetFiles;
        }

        // Implied byte budget. Guard the multiplication against int overflow; if it
        // would overflow, the byte budget is effectively unbounded, so the file
        // budget is the only binding ceiling.
        if ($budgetFiles > intdiv(PHP_INT_MAX, self::BYTES_PER_FILE_CEILING)) {
            return $budgetFiles;
        }
        $byteBudget = $budgetFiles * self::BYTES_PER_FILE_CEILING;

        // Bytes fit the byte budget → not byte-heavy → file budget stands.
        if ($estimatedBytes <= $byteBudget) {
            return $budgetFiles;
        }

        // Byte-heavy: shrink to the count of average-sized files that fit the byte
        // budget. avgBytesPerFile ≥ 1 (rounded up) so the divisor is positive and a
        // repo of tiny-but-numerous files is not over-penalised. At least 1 so the
        // staged plan still has a meaningful first tier.
        $avgBytesPerFile = max(1, intdiv($estimatedBytes, $totalFiles));
        $byteDerived = max(1, intdiv($byteBudget, $avgBytesPerFile));

        return min($budgetFiles, $byteDerived);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveBudgetFiles(array $opts): int
    {
        if (array_key_exists('budget_files', $opts)) {
            $candidate = $this->intOrNull($opts['budget_files']);
            if ($candidate !== null) {
                return max(self::MIN_BUDGET_FILES, $candidate);
            }
        }

        $configured = $this->intOrNull(config('atlas.code_graph.first_index_budget_files', 5000));

        return max(self::MIN_BUDGET_FILES, $configured ?? 5000);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveBatchSize(array $opts): int
    {
        if (array_key_exists('batch_size', $opts)) {
            $candidate = $this->intOrNull($opts['batch_size']);
            if ($candidate !== null) {
                return max(self::MIN_BATCH_SIZE, $candidate);
            }
        }

        $configured = $this->intOrNull(config('atlas.code_graph.index_batch_size', 500));

        return max(self::MIN_BATCH_SIZE, $configured ?? 500);
    }

    /**
     * Coerce a config/opt value to an int, or null if it is not a usable number.
     * Accepts int, float (truncated toward zero), and numeric strings. Rejects NaN
     * and infinities so a garbage config can never poison the budget arithmetic.
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }

            return (int) $float;
        }

        return null;
    }

    /** Clamp an int argument to a non-negative value (fail-safe floor). */
    private function clampNonNegative(int $value): int
    {
        return $value < 0 ? 0 : $value;
    }
}

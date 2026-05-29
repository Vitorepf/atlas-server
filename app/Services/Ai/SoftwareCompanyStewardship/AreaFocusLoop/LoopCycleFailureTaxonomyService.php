<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AP-810 / LHL — Loop Cycle Failure Taxonomy.
 *
 * Read-only, deterministic, input-seam driven. Answers a single question about
 * one NON-merged loop cycle: WHY did it not merge, and HOW should the supervisor
 * adapt? It maps a cycle's terminal status + blocker reasons onto a 5-tier
 * taxonomy and a bounded recovery action, so the AP-790 runner can stop
 * blind-counting blocked-in-row and instead respond to the actual failure class.
 *
 * The five tiers (escalating from cheapest-to-recover to terminal):
 *   1. setup_failure     — environment/worktree/autoload/sandbox is broken;
 *                          recover by re-preparing the environment (1 retry).
 *   2. execution_failure — provider/senior-loop did not run or timed out;
 *                          recover by retrying with richer context (1 retry).
 *   3. quality_failure   — code ran but judge/test/psr rejected it, repair tried;
 *                          recover via the repair agent (2 retries).
 *   4. policy_failure    — work was valid but the merge was withheld by policy;
 *                          recover by re-attempting the merge gate (2 retries).
 *   5. budget_exhausted  — a budget/backlog ceiling was hit; STOP HONESTLY,
 *                          never retry (0 retries) — there is no more work.
 *
 * It NEVER runs the loop, NEVER invokes a provider, NEVER merges, NEVER deletes a
 * branch. It only classifies. The runner (Reliable24hLoopRunnerService) consumes
 * the verdict to drive cascade detection and tier-aware retry budgets.
 *
 * Honesty rule (operator does not accept false claims): a tier-5 budget_exhausted
 * cycle is terminal — `recovery_action=stop_honest`, `max_retries=0`. Pretending
 * there is more work after a real budget/backlog ceiling is forbidden.
 */
final class LoopCycleFailureTaxonomyService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_cycle_failure_taxonomy.v1';

    public const TIER_SETUP = 1;

    public const TIER_EXECUTION = 2;

    public const TIER_QUALITY = 3;

    public const TIER_POLICY = 4;

    public const TIER_BUDGET = 5;

    public const TIER_SETUP_NAME = 'setup_failure';

    public const TIER_EXECUTION_NAME = 'execution_failure';

    public const TIER_QUALITY_NAME = 'quality_failure';

    public const TIER_POLICY_NAME = 'policy_failure';

    public const TIER_BUDGET_NAME = 'budget_exhausted';

    public const RECOVERY_AUTO_REPAIR_ENV = 'auto_repair_env';

    public const RECOVERY_RETRY_WITH_CONTEXT = 'retry_with_context';

    public const RECOVERY_REPAIR_AGENT = 'repair_agent';

    public const RECOVERY_MERGE_RETRY = 'merge_retry';

    public const RECOVERY_STOP_HONEST = 'stop_honest';

    /**
     * Tier resolution order. Highest tier (terminal budget) is checked FIRST so a
     * cycle that hit a real ceiling is never mis-classified as a recoverable
     * quality/setup failure just because it also carries an incidental blocker.
     * Within the remaining tiers we go setup -> execution -> quality -> policy.
     *
     * Each entry: [tier, tier_name, recovery_action, max_retries, [needle, ...]].
     * Needles are matched as substrings (case-insensitive) against every blocker
     * reason AND the cycle final_status, so prefixed canonical variants such as
     * `owner_runtime_senior_loop_execution_not_passed` and `owner_runtime_provider_timeout`
     * are caught without enumerating every prefix.
     *
     * @var list<array{0:int,1:string,2:string,3:int,4:list<string>}>
     */
    private const TIER_RULES = [
        [
            self::TIER_BUDGET, self::TIER_BUDGET_NAME, self::RECOVERY_STOP_HONEST, 0,
            ['max_merges_hit', 'max_merges_reached', 'max_runtime_hit', 'max_runtime_minutes_reached',
                'max_runtime_seconds', 'max_cycles_reached', 'backlog_exhausted', 'budget_exhausted',
                'no_admissible_candidate'],
        ],
        [
            self::TIER_SETUP, self::TIER_SETUP_NAME, self::RECOVERY_AUTO_REPAIR_ENV, 1,
            ['worktree', 'autoload', 'sandbox_error', 'sandbox_materialization', 'sandbox_not',
                'git_worktree', 'created_worktree_not_git', 'branch_worktree_isolation_missing',
                'env_broken', 'environment'],
        ],
        [
            self::TIER_EXECUTION, self::TIER_EXECUTION_NAME, self::RECOVERY_RETRY_WITH_CONTEXT, 1,
            ['senior_loop_execution_not_passed', 'senior_loop_not_executed', 'senior_loop_failed',
                'owner_runtime_result_failed', 'owner_runtime_failed', 'runtime_result_failed',
                'provider_timeout', 'provider_not_invoked', 'execution_not_passed', 'execution_failed'],
        ],
        [
            self::TIER_QUALITY, self::TIER_QUALITY_NAME, self::RECOVERY_REPAIR_AGENT, 2,
            ['judge_rejected', 'judge_not_accept', 'judge_must_pass', 'judge_repair_required',
                'test_failed', 'tests_failed', 'test_failure', 'psr_error', 'psr_failure',
                'repair_exhausted', 'quarantine_after_repair_exhausted', 'quality_failure'],
        ],
        [
            self::TIER_POLICY, self::TIER_POLICY_NAME, self::RECOVERY_MERGE_RETRY, 2,
            ['merge_not_performed', 'auto_merge_policy_not_satisfied', 'auto_merge_policy',
                'operator_review_required', 'merge_withheld', 'policy_failure'],
        ],
    ];

    /**
     * Classify one non-merged loop cycle into the failure taxonomy.
     *
     * Accepts the same cycle shape the runner already holds (`final_status`,
     * `blockers`, `merge_performed`, optional `stop_reason`). Optional inputs:
     *   - `recurrence` (int): how many times THIS same tier/finding has recurred
     *     in a row, used to compute `is_cascade_safe`.
     *   - `same_tier_consecutive` (int): alias for `recurrence`.
     *
     * @param  array<string,mixed>  $cycleResult
     * @return array{tier:int,tier_name:string,specific_reason:string,recovery_action:string,max_retries:int,is_cascade_safe:bool,classified:bool,schema_version:string,blockers:list<string>,recurrence:int}
     */
    public function classify(array $cycleResult): array
    {
        $blockers = $this->blockers($cycleResult);
        $finalStatus = $this->str($cycleResult['final_status'] ?? '');
        $stopReason = $this->str($cycleResult['stop_reason'] ?? '');
        $mergePerformed = (bool) ($cycleResult['merge_performed'] ?? false);

        // The full haystack we match needles against: every blocker reason, the
        // terminal final_status and any stop_reason the runner attached.
        $haystack = array_merge($blockers, array_filter([$finalStatus, $stopReason], static fn (string $s): bool => $s !== ''));

        $recurrence = $this->recurrence($cycleResult);

        [$tier, $tierName, $recovery, $maxRetries, $specificReason, $classified] = $this->resolveTier($haystack, $blockers, $finalStatus, $mergePerformed);

        // Cascade safety: a tier is "cascade safe" while it has NOT recurred more
        // than its own retry budget. Once the SAME tier recurs beyond max_retries,
        // retrying is no longer safe (it would loop forever) and the supervisor
        // must escalate/halt instead of blindly re-trying. A terminal budget tier
        // (max_retries=0) is never cascade-safe to retry — there is no more work.
        $isCascadeSafe = $maxRetries > 0 && $recurrence <= $maxRetries;

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'tier' => $tier,
            'tier_name' => $tierName,
            'specific_reason' => $specificReason,
            'recovery_action' => $recovery,
            'max_retries' => $maxRetries,
            'is_cascade_safe' => $isCascadeSafe,
            // True when a known tier NEEDLE matched. False when the cycle only fell
            // through to a generic bucket (an unrecognised blocker, or a non-merged
            // cycle with no blocker). The supervisor uses this so it never escalates
            // an UNCLASSIFIED blocker as if it were a known execution failure.
            'classified' => $classified,
            'recurrence' => $recurrence,
            'blockers' => $blockers,
        ];
    }

    /**
     * Per-failure-class remediation hints entry (step 2 of 3).
     *
     * Validates input shape. Empty input returns
     * {@see PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::defaults}.
     * Taxonomy verdict wiring and hint resolution are step 3.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function perFailureClassRemediationHints(array $input = []): array
    {
        $this->validatePerFailureClassRemediationHintsInput($input);

        return PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::defaults()->toArray();
    }

    /**
     * Resolve the tier for a cycle. Budget ceilings win first (terminal), then the
     * remaining tiers in setup -> execution -> quality -> policy order. A cycle with
     * NO recognised blocker but a blocked final_status is treated as a generic
     * execution failure (something ran and stopped without a classified reason);
     * a cycle with no blocker and no blocked status that still did not merge is a
     * policy failure (work happened but nothing merged).
     *
     * @param  list<string>  $haystack
     * @param  list<string>  $blockers
     * @return array{0:int,1:string,2:string,3:int,4:string,5:bool}
     */
    private function resolveTier(array $haystack, array $blockers, string $finalStatus, bool $mergePerformed): array
    {
        foreach (self::TIER_RULES as [$tier, $tierName, $recovery, $maxRetries, $needles]) {
            $match = $this->firstMatch($haystack, $needles);
            if ($match !== null) {
                return [$tier, $tierName, $recovery, $maxRetries, $this->specificReason($blockers, $match['needle'], $finalStatus), true];
            }
        }

        // No recognised blocker. Decide between an unclassified execution failure
        // (it blocked) and a policy failure (it neither blocked nor merged). Both are
        // UNCLASSIFIED (classified=false): the supervisor must not escalate an
        // unrecognised blocker as if it were a known execution/provider failure.
        $blocked = $finalStatus === 'blocked' || $blockers !== [];
        if ($blocked) {
            return [
                self::TIER_EXECUTION,
                self::TIER_EXECUTION_NAME,
                self::RECOVERY_RETRY_WITH_CONTEXT,
                1,
                $this->specificReason($blockers, '', $finalStatus, 'unclassified_execution_failure'),
                false,
            ];
        }

        return [
            self::TIER_POLICY,
            self::TIER_POLICY_NAME,
            self::RECOVERY_MERGE_RETRY,
            2,
            $mergePerformed ? 'merge_performed_no_failure' : 'merge_not_performed',
            false,
        ];
    }

    /**
     * Find the first tier-needle that matches any item in the haystack. Returns the
     * matched needle plus the exact haystack entry (the real reason string) so the
     * specific_reason stays the canonical blocker, not a generic label.
     *
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     * @return array{needle:string,reason:string}|null
     */
    private function firstMatch(array $haystack, array $needles): ?array
    {
        foreach ($haystack as $entry) {
            $lower = strtolower($entry);
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return ['needle' => $needle, 'reason' => $entry];
                }
            }
        }

        return null;
    }

    /**
     * Pick the most specific reason string for the report: prefer the exact blocker
     * that matched the tier needle, then any blocker, then the final_status, then a
     * supplied fallback. Never returns an empty string.
     *
     * @param  list<string>  $blockers
     */
    private function specificReason(array $blockers, string $matchedNeedle, string $finalStatus, string $fallback = 'unknown_failure'): string
    {
        if ($matchedNeedle !== '') {
            foreach ($blockers as $blocker) {
                if (str_contains(strtolower($blocker), $matchedNeedle)) {
                    return $blocker;
                }
            }
            // The needle matched final_status/stop_reason rather than a blocker.
            return $matchedNeedle;
        }

        if ($blockers !== []) {
            return $blockers[0];
        }
        if ($finalStatus !== '') {
            return $finalStatus;
        }

        return $fallback;
    }

    /**
     * @param  array<string,mixed>  $cycleResult
     * @return list<string>
     */
    private function blockers(array $cycleResult): array
    {
        $raw = (array) ($cycleResult['blockers'] ?? []);

        return array_values(array_filter(array_map(
            fn (mixed $b): string => $this->str($b),
            $raw,
        ), static fn (string $b): bool => $b !== ''));
    }

    /**
     * @param  array<string,mixed>  $cycleResult
     */
    private function recurrence(array $cycleResult): int
    {
        foreach (['recurrence', 'same_tier_consecutive', 'consecutive'] as $key) {
            if (array_key_exists($key, $cycleResult) && is_numeric($cycleResult[$key])) {
                return max(0, (int) $cycleResult[$key]);
            }
        }

        return 0;
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function validatePerFailureClassRemediationHintsInput(array $input): void
    {
        if ($input === []) {
            return;
        }

        $allowedKeys = [
            'area_id',
            'focus',
            'tier',
            'tier_name',
            'specific_reason',
            'recovery_action',
            'classified',
        ];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException("Unknown per-failure-class remediation hints input key: {$key}");
            }
        }

        if (array_key_exists('area_id', $input) && ! is_string($input['area_id'])) {
            throw new \InvalidArgumentException('area_id must be a string.');
        }

        if (array_key_exists('focus', $input) && ! is_string($input['focus'])) {
            throw new \InvalidArgumentException('focus must be a string.');
        }

        if (array_key_exists('tier', $input) && ! is_numeric($input['tier'])) {
            throw new \InvalidArgumentException('tier must be numeric.');
        }

        if (array_key_exists('tier_name', $input) && ! is_string($input['tier_name'])) {
            throw new \InvalidArgumentException('tier_name must be a string.');
        }

        if (array_key_exists('specific_reason', $input) && ! is_string($input['specific_reason'])) {
            throw new \InvalidArgumentException('specific_reason must be a string.');
        }

        if (array_key_exists('recovery_action', $input) && ! is_string($input['recovery_action'])) {
            throw new \InvalidArgumentException('recovery_action must be a string.');
        }

        if (array_key_exists('classified', $input) && ! is_bool($input['classified'])) {
            throw new \InvalidArgumentException('classified must be a boolean.');
        }
    }
}

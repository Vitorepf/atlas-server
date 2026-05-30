<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;

/**
 * Foundry AP-B · Exhaustion & Rarity Gate (Invariant I8).
 *
 * GENERATES NOTHING. This service DECIDES eligibility only. It NEVER proposes,
 * NEVER writes canon, NEVER mutates production state, NEVER triggers generation
 * (that is the downstream, separately-gated AP-C). It DOES NOT replace the
 * loop's honest backlog_exhausted stop: the gate is consulted ONLY behind a
 * default-off Frontier flag and, at the honest-stop point, is ADVISORY — it can
 * never fabricate a merge, never suppress the honest stop. `fallback_is_honest_stop`
 * is always true to record that the #1 honest stop remains the fallback.
 *
 * Invariant I8 (Budget & Rarity Gate). Status is the canonical 4-state:
 *   - skipped      : flag off; no governor/runner reads acted upon.
 *   - blocked      : premium spend over the window ceiling (HARD cap; wins over
 *                    exhaustion). Driven by LoopResourceGovernor status=stop OR any
 *                    headroom<0 on provider_calls / token_estimate. BOTH ceilings are
 *                    set to the premium ceiling so neither metric alone escapes the cap.
 *   - not_eligible : backlog available, OR fewer than window_n ledger records, OR any
 *                    cycle in the window inconclusive (no positively-measured
 *                    zero-admissible signal), OR rarity leg not below_floor.
 *   - eligible     : ONLY on MEASURED exhaustion — window_n records exist; 0 admissible
 *                    packets POSITIVELY measured for N consecutive non-transient cycles;
 *                    stable_metrics (no merge/progress in window); budget_leg ok (both
 *                    ceilings under cap, headroom>=0); rarity_leg below_floor.
 *
 * Read-only / deterministic / input-seam driven. Reuses LoopResourceGovernor (budget
 * leg), BacklogDepthGovernor (rarity leg), Reliable24hLoopRunner (exhaustion window
 * source) and AreaFocusCandidateQuarantine (transient-blocker exclusion). It composes
 * those owners — it never duplicates their below-floor / budget logic.
 */
final class FoundryExhaustionRarityGateService
{
    public const GATE_SCHEMA = 'atlas.foundry.exhaustion_rarity_gate.v1';

    public const STATUS_NOT_ELIGIBLE = 'not_eligible';

    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_SKIPPED = 'skipped';

    /** Default consecutive measured-zero-admissible cycles required (validated >=2). */
    public const DEFAULT_WINDOW_N = 3;

    /** Rarity floor (tighter than BacklogDepthGovernor DEFAULT_FLOOR=10). */
    public const DEFAULT_RARITY_FLOOR = 3;

    /** @var array<string,mixed>|null */
    private ?array $runnerReportOverride = null;

    /** @var array<string,mixed>|null */
    private ?array $budgetReportOverride = null;

    /** @var array<string,mixed>|null */
    private ?array $backlogReportOverride = null;

    public function __construct(
        private readonly LoopResourceGovernorService $resourceGovernor,
        private readonly BacklogDepthGovernorService $backlogGovernor,
        private readonly Reliable24hLoopRunnerService $loopRunner,
        private readonly AreaFocusCandidateQuarantineService $quarantine,
    ) {}

    /**
     * Input-override seam mirroring setRepoRootForTesting: inject the
     * exhaustion-window ledger records directly (key `ledger_records`), bypassing
     * the real Reliable24hLoopRunnerService read.
     *
     * @param  array<string,mixed>|null  $report
     */
    public function setRunnerReportForTesting(?array $report): void
    {
        $this->runnerReportOverride = $report;
    }

    /**
     * Override the budget leg (LoopResourceGovernor::evaluate output).
     *
     * @param  array<string,mixed>|null  $report
     */
    public function setBudgetReportForTesting(?array $report): void
    {
        $this->budgetReportOverride = $report;
    }

    /**
     * Override the rarity leg (BacklogDepthGovernor::assess output).
     *
     * @param  array<string,mixed>|null  $report
     */
    public function setBacklogReportForTesting(?array $report): void
    {
        $this->backlogReportOverride = $report;
    }

    /**
     * Single entrypoint. Returns atlas.foundry.exhaustion_rarity_gate.v1 with EXACTLY
     * 16 keys (validateShape unexpected_keys===[]). DECIDES only; triggers nothing.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input = []): array
    {
        // ---- Default-off gate: flag resolves false => skipped, zero reads ----
        if (! $this->flagEnabled($input)) {
            return $this->emit(
                status: self::STATUS_SKIPPED,
                checks: [$this->check('frontier_flag', 'skip', 'frontier_mode off; honest backlog_exhausted stop is authoritative')],
                exhaustionDepth: 0,
                consecutiveZeroCycles: 0,
                windowN: $this->resolveWindowN($input),
                stableMetrics: false,
                budgetLeg: 'not_evaluated',
                rarityLeg: 'not_evaluated',
                packetsCount: 0,
                premiumSpend: 0,
                premiumCeiling: $this->resolvePremiumCeiling($input),
                dropReason: 'frontier_flag_off',
            );
        }

        $windowN = $this->resolveWindowN($input);
        $premiumCeiling = $this->resolvePremiumCeiling($input);

        // ---- Budget leg (HARD cap, wins over exhaustion) ---------------------
        $budgetReport = $this->budgetReport($input, $premiumCeiling);
        $budgetStatus = (string) ($budgetReport['status'] ?? '');
        $resourceSummary = is_array($budgetReport['resource_summary'] ?? null) ? $budgetReport['resource_summary'] : [];
        $premiumSpend = $this->premiumSpend($resourceSummary);
        $budgetOverCap = $budgetStatus === LoopResourceGovernorService::STATUS_STOP
            || $this->headroomNegative($resourceSummary, 'provider_calls')
            || $this->headroomNegative($resourceSummary, 'token_estimate');

        if ($budgetOverCap) {
            return $this->emit(
                status: self::STATUS_BLOCKED,
                checks: [
                    $this->check('budget_cap', 'fail', 'premium spend over window ceiling (HARD cap); overrides any exhaustion'),
                ],
                exhaustionDepth: 0,
                consecutiveZeroCycles: 0,
                windowN: $windowN,
                stableMetrics: false,
                budgetLeg: 'over_cap',
                rarityLeg: 'not_evaluated',
                packetsCount: 0,
                premiumSpend: $premiumSpend,
                premiumCeiling: $premiumCeiling,
                dropReason: 'premium_spend_over_ceiling',
            );
        }

        // Finding 19 fix: budget under-determination FAILS CLOSED. If the resolved
        // report carries no usable headroom (value/hard or remaining_to_hard) for
        // EITHER metric AND the status is not an explicit go, the budget signal is
        // unreadable — a garbled signal must NOT be read as under-cap. Treated as
        // over-cap (blocked). The explicit STATUS_STOP / headroom-negative / value>hard
        // branches above are untouched and still win (they already returned).
        if (! $this->budgetSignalReadable($resourceSummary) && $budgetStatus !== LoopResourceGovernorService::STATUS_OK) {
            return $this->emit(
                status: self::STATUS_BLOCKED,
                checks: [
                    $this->check('budget_cap', 'fail', 'budget signal under-determined (no usable headroom on either metric, status not go); fail-closed over-cap'),
                ],
                exhaustionDepth: 0,
                consecutiveZeroCycles: 0,
                windowN: $windowN,
                stableMetrics: false,
                budgetLeg: 'over_cap',
                rarityLeg: 'not_evaluated',
                packetsCount: 0,
                premiumSpend: $premiumSpend,
                premiumCeiling: $premiumCeiling,
                dropReason: 'budget_signal_unavailable',
            );
        }

        // ---- Rarity leg (scarcity confirmed ONLY when status=below_floor) ----
        $backlogReport = $this->backlogReport($input);
        $backlogStatus = (string) ($backlogReport['status'] ?? '');
        $packetsCount = (int) ($backlogReport['packets_count'] ?? 0);
        $rarityBelowFloor = $backlogStatus === BacklogDepthGovernorService::STATUS_BELOW_FLOOR;
        $rarityLeg = $rarityBelowFloor ? 'below_floor' : 'available';

        // ---- Exhaustion window from the loop ledger --------------------------
        $records = $this->ledgerRecords($input);
        $recordCount = count($records);

        $checks = [
            $this->check('budget_cap', 'pass', 'premium spend under window ceiling on both metrics'),
        ];

        // Backlog available via the runner window (merged/progress, or measured
        // admissible_packet_count>=1) OR the rarity leg reporting ok => not_eligible.
        if (! $rarityBelowFloor) {
            $checks[] = $this->check('rarity', 'fail', 'BacklogDepthGovernor status != below_floor; backlog exists');

            return $this->emit(
                status: self::STATUS_NOT_ELIGIBLE,
                checks: $checks,
                exhaustionDepth: 0,
                consecutiveZeroCycles: 0,
                windowN: $windowN,
                stableMetrics: false,
                budgetLeg: 'ok',
                rarityLeg: $rarityLeg,
                packetsCount: $packetsCount,
                premiumSpend: $premiumSpend,
                premiumCeiling: $premiumCeiling,
                dropReason: 'backlog_available',
            );
        }
        $checks[] = $this->check('rarity', 'pass', 'BacklogDepthGovernor below_floor; scarcity confirmed');

        if ($this->windowHasMergeOrProgress($records) || $this->windowHasAdmissibleWork($records)) {
            $checks[] = $this->check('stable_metrics', 'fail', 'merge/progress or admissible work measured in window');

            return $this->emit(
                status: self::STATUS_NOT_ELIGIBLE,
                checks: $checks,
                exhaustionDepth: 0,
                consecutiveZeroCycles: 0,
                windowN: $windowN,
                stableMetrics: false,
                budgetLeg: 'ok',
                rarityLeg: $rarityLeg,
                packetsCount: $packetsCount,
                premiumSpend: $premiumSpend,
                premiumCeiling: $premiumCeiling,
                dropReason: 'backlog_available',
            );
        }
        $checks[] = $this->check('stable_metrics', 'pass', 'no merge/progress/admissible work in window');

        // Fresh / short ledger can NEVER be eligible.
        if ($recordCount < $windowN) {
            $checks[] = $this->check('window_records', 'fail', "{$recordCount} ledger records < window_n {$windowN}");

            return $this->emit(
                status: self::STATUS_NOT_ELIGIBLE,
                checks: $checks,
                exhaustionDepth: $recordCount,
                consecutiveZeroCycles: 0,
                windowN: $windowN,
                stableMetrics: true,
                budgetLeg: 'ok',
                rarityLeg: $rarityLeg,
                packetsCount: $packetsCount,
                premiumSpend: $premiumSpend,
                premiumCeiling: $premiumCeiling,
                dropReason: 'evidence_insufficient',
            );
        }
        $checks[] = $this->check('window_records', 'pass', "{$recordCount} ledger records >= window_n {$windowN}");

        // Positively-measured zero-admissible signal for N consecutive non-transient
        // cycles. A single stop-cycle backlog_exhausted blocker can NEVER stand in for
        // N measured cycles, and any inconclusive cycle => evidence_insufficient.
        $consecutive = $this->consecutiveMeasuredZeroAdmissible($records, $windowN, $this->resolveMaxTransientSkips($input, $windowN));

        if ($consecutive < $windowN) {
            $checks[] = $this->check('zero_admissible_window', 'fail', "only {$consecutive}/{$windowN} cycles positively measured zero-admissible");

            return $this->emit(
                status: self::STATUS_NOT_ELIGIBLE,
                checks: $checks,
                exhaustionDepth: $consecutive,
                consecutiveZeroCycles: $consecutive,
                windowN: $windowN,
                stableMetrics: true,
                budgetLeg: 'ok',
                rarityLeg: $rarityLeg,
                packetsCount: $packetsCount,
                premiumSpend: $premiumSpend,
                premiumCeiling: $premiumCeiling,
                dropReason: 'evidence_insufficient',
            );
        }
        $checks[] = $this->check('zero_admissible_window', 'pass', "{$consecutive}/{$windowN} cycles positively measured zero-admissible");

        // ALL legs hold: MEASURED exhaustion + scarcity + budget ok + stable metrics.
        return $this->emit(
            status: self::STATUS_ELIGIBLE,
            checks: $checks,
            exhaustionDepth: $consecutive,
            consecutiveZeroCycles: $consecutive,
            windowN: $windowN,
            stableMetrics: true,
            budgetLeg: 'ok',
            rarityLeg: $rarityLeg,
            packetsCount: $packetsCount,
            premiumSpend: $premiumSpend,
            premiumCeiling: $premiumCeiling,
            dropReason: null,
        );
    }

    // ---------------------------------------------------------------- legs

    /**
     * Default-off resolution. Mirrors the multi_agent_workcell convention: the
     * config flag defaults false, and an explicit $input override must be === true.
     *
     * @param  array<string,mixed>  $input
     */
    private function flagEnabled(array $input): bool
    {
        $config = (bool) config('atlas.software_company_stewardship.frontier_mode', false);
        $override = ($input['exhaustion_rarity_gate_enabled'] ?? null) === true;

        return $config || $override;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveWindowN(array $input): int
    {
        $value = (int) ($input['window_n'] ?? self::DEFAULT_WINDOW_N);

        return $value >= 2 ? $value : self::DEFAULT_WINDOW_N;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolvePremiumCeiling(array $input): int
    {
        $value = (int) ($input['premium_ceiling'] ?? 0);

        return max(0, $value);
    }

    /**
     * Finding 20: maximum transient-infra skips tolerated WITHIN the exhaustion
     * window. Small and bounded so a long run of transient cycles can no longer
     * silently stitch non-adjacent measured-zero cycles into a "consecutive" streak.
     * Default = window_n; input-overridable; clamped to >= 0.
     *
     * @param  array<string,mixed>  $input
     */
    private function resolveMaxTransientSkips(array $input, int $windowN): int
    {
        if (array_key_exists('max_transient_skips', $input)) {
            return max(0, (int) $input['max_transient_skips']);
        }

        return $windowN;
    }

    /**
     * Budget leg. AP-B sets BOTH hard_ceilings.provider_calls AND
     * hard_ceilings.token_estimate to the premium ceiling so neither metric alone
     * escapes the HARD cap.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function budgetReport(array $input, int $premiumCeiling): array
    {
        if ($this->budgetReportOverride !== null) {
            return $this->budgetReportOverride;
        }

        $usage = is_array($input['budget_usage'] ?? null) ? $input['budget_usage'] : [];

        return $this->resourceGovernor->evaluate([
            'usage' => $usage,
            'hard_ceilings' => [
                'provider_calls' => $premiumCeiling,
                'token_estimate' => $premiumCeiling,
            ],
        ]);
    }

    /**
     * Rarity leg. AP-B passes floor=rarity_floor as an override; the below-floor
     * decision lives entirely inside BacklogDepthGovernor (never duplicated here).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function backlogReport(array $input): array
    {
        if ($this->backlogReportOverride !== null) {
            return $this->backlogReportOverride;
        }

        $assessInput = is_array($input['backlog_input'] ?? null) ? $input['backlog_input'] : [];
        $assessInput['floor'] = (int) ($input['rarity_floor'] ?? self::DEFAULT_RARITY_FLOOR);

        return $this->backlogGovernor->assess($assessInput);
    }

    /**
     * Exhaustion-window ledger records (read-only). Input override `ledger_records`
     * wins; otherwise read the real Reliable24hLoopRunnerService ledger.
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function ledgerRecords(array $input): array
    {
        if (is_array($this->runnerReportOverride['ledger_records'] ?? null)) {
            return array_values(array_filter($this->runnerReportOverride['ledger_records'], 'is_array'));
        }
        if (is_array($input['ledger_records'] ?? null)) {
            return array_values(array_filter($input['ledger_records'], 'is_array'));
        }

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        return array_values(array_filter($this->loopRunner->readLedgerRecords($area, $focus), 'is_array'));
    }

    /**
     * @param  array<string,mixed>  $resourceSummary
     */
    private function headroomNegative(array $resourceSummary, string $metric): bool
    {
        $headroom = is_array($resourceSummary['headroom'] ?? null) ? $resourceSummary['headroom'] : [];
        $entry = is_array($headroom[$metric] ?? null) ? $headroom[$metric] : [];
        $remaining = $entry['remaining_to_hard'] ?? null;
        if ($remaining !== null && (int) $remaining < 0) {
            return true;
        }

        $value = $entry['value'] ?? null;
        $hard = $entry['hard_ceiling'] ?? null;
        if ($value !== null && $hard !== null) {
            return (int) $value > (int) $hard;
        }

        return false;
    }

    /**
     * Finding 19: a budget signal is READABLE only when at least one metric carries a
     * usable headroom entry. A metric is usable when it has a non-null
     * `remaining_to_hard`, OR both `value` and `hard_ceiling`. If NEITHER
     * provider_calls NOR token_estimate is usable, the signal is under-determined and
     * (combined with a non-go status) must fail closed rather than read as under-cap.
     *
     * @param  array<string,mixed>  $resourceSummary
     */
    private function budgetSignalReadable(array $resourceSummary): bool
    {
        return $this->metricHeadroomUsable($resourceSummary, 'provider_calls')
            || $this->metricHeadroomUsable($resourceSummary, 'token_estimate');
    }

    /**
     * @param  array<string,mixed>  $resourceSummary
     */
    private function metricHeadroomUsable(array $resourceSummary, string $metric): bool
    {
        $headroom = is_array($resourceSummary['headroom'] ?? null) ? $resourceSummary['headroom'] : [];
        $entry = is_array($headroom[$metric] ?? null) ? $headroom[$metric] : [];

        if (($entry['remaining_to_hard'] ?? null) !== null) {
            return true;
        }

        return ($entry['value'] ?? null) !== null && ($entry['hard_ceiling'] ?? null) !== null;
    }

    /**
     * @param  array<string,mixed>  $resourceSummary
     */
    private function premiumSpend(array $resourceSummary): int
    {
        return max(
            (int) ($resourceSummary['provider_calls'] ?? 0),
            (int) ($resourceSummary['token_estimate'] ?? 0),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function windowHasMergeOrProgress(array $records): bool
    {
        foreach ($records as $record) {
            $outcome = strtolower((string) ($record['outcome'] ?? ''));
            if ($outcome === 'merged' || $outcome === 'progress') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function windowHasAdmissibleWork(array $records): bool
    {
        foreach ($records as $record) {
            if (array_key_exists('admissible_packet_count', $record)
                && is_numeric($record['admissible_packet_count'])
                && (int) $record['admissible_packet_count'] >= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count consecutive (most-recent-first) cycles that POSITIVELY measure
     * zero-admissible. Transient-infra blocked cycles are excluded from the count
     * (they neither confirm nor break exhaustion). A cycle is a positive
     * zero-admissible measurement when admissible_packet_count===0 (when present),
     * OR outcome=blocked with a backlog_exhausted blocker. Any non-transient cycle
     * that is NOT a positive measurement breaks the streak (inconclusive).
     *
     * Finding 20 fix: transient skips are BOUNDED. The window_n positive-zero cycles
     * must fall within the most-recent (window_n + max_transient_skips) records. If
     * accumulated transient skips exceed max_transient_skips before window_n positive
     * cycles are measured, the run is too sparse to assert a "consecutive" streak: the
     * streak is abandoned (returns the count so far, < window_n) => evidence_insufficient.
     *
     * @param  list<array<string,mixed>>  $records
     */
    private function consecutiveMeasuredZeroAdmissible(array $records, int $windowN, int $maxTransientSkips): int
    {
        $ordered = array_reverse($records);
        $consecutive = 0;
        $transientSkips = 0;

        foreach ($ordered as $record) {
            $blockers = array_values(array_filter((array) ($record['blockers'] ?? []), 'is_string'));

            // Transient-infra blocked cycles do not count and do not break the streak,
            // but they are bounded: too many before window_n is reached abandons the run.
            if ($this->quarantine->hasTransientBlocker($blockers)) {
                $transientSkips++;
                if ($transientSkips > $maxTransientSkips) {
                    break;
                }

                continue;
            }

            if ($this->isPositiveZeroAdmissible($record, $blockers)) {
                $consecutive++;
                if ($consecutive >= $windowN) {
                    return $consecutive;
                }

                continue;
            }

            // Non-transient, not a positive measurement => inconclusive; streak breaks.
            break;
        }

        return $consecutive;
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  list<string>  $blockers
     */
    private function isPositiveZeroAdmissible(array $record, array $blockers): bool
    {
        if (array_key_exists('admissible_packet_count', $record)
            && is_numeric($record['admissible_packet_count'])) {
            return (int) $record['admissible_packet_count'] === 0;
        }

        $outcome = strtolower((string) ($record['outcome'] ?? ''));

        return $outcome === 'blocked' && in_array('backlog_exhausted', $blockers, true);
    }

    // ---------------------------------------------------------------- emit

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function emit(
        string $status,
        array $checks,
        int $exhaustionDepth,
        int $consecutiveZeroCycles,
        int $windowN,
        bool $stableMetrics,
        string $budgetLeg,
        string $rarityLeg,
        int $packetsCount,
        int $premiumSpend,
        int $premiumCeiling,
        ?string $dropReason,
    ): array {
        $payload = [
            'schema_version' => self::GATE_SCHEMA,
            'status' => $status,
            'checks' => $checks,
            'exhaustion_depth' => $exhaustionDepth,
            'consecutive_admissible_zero_cycles' => $consecutiveZeroCycles,
            'window_n' => $windowN,
            'stable_metrics' => $stableMetrics,
            'budget_leg' => $budgetLeg,
            'rarity_leg' => $rarityLeg,
            'packets_count' => $packetsCount,
            'premium_spend' => $premiumSpend,
            'premium_ceiling' => $premiumCeiling,
            'drop_reason' => $dropReason,
            // The #1 honest stop remains the fallback; the gate never replaces it.
            'fallback_is_honest_stop' => true,
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['gate_hash'] = MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * Hash identity over the decided shape (everything except the hash itself).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['gate_hash']);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function check(string $name, string $result, string $detail): array
    {
        return ['name' => $name, 'result' => $result, 'detail' => $detail];
    }

    /**
     * Decide-only, read-only. Copied verbatim from FoundryEvidenceVerifierService's
     * claim_policy keys; AP-B writes NOTHING, so read_only=true / writes_state=false.
     *
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'ledger_record_invoked' => false,
            'canonical_doc_write_allowed' => false,
            'provider_invoked' => false,
            'generates_code' => false,
        ];
    }
}

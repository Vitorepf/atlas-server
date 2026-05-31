<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-12 — Loop Quality Drift Detector (AP-809).
 *
 * Watches the long-horizon loop for the slow rot that does NOT trip a single
 * cycle gate but tells you the factory is getting worse: more cycles needing
 * repair, more blocked cycles, slower tests, the same subsystem churning over
 * and over, reverts/rollbacks piling up, complexity/duplication climbing,
 * evidence going incomplete, and the operator having to step in more often.
 *
 * It answers exactly one question:
 *
 *   > Across recent history, is loop quality `stable`, `drifting`, or `degraded`?
 *
 * This service is read-only / deterministic / input-seam driven. It NEVER
 * invokes a provider, NEVER runs the loop, NEVER merges, NEVER deletes a
 * branch/worktree, NEVER mutates code. It DIAGNOSES drift and RECOMMENDS
 * de-risking actions (reduce autonomy tier, pause high-risk packets, request
 * architecture/security review, generate a repair backlog, stop long-run
 * promotion). It never executes any of those actions.
 *
 * Honesty rules (operator does not accept false claims):
 *   - rising repair/blocked rates are NEVER smoothed into `stable`;
 *   - a degraded loop ALWAYS sets stop_promotion=true (a degrading factory is
 *     never promoted to a longer unattended run);
 *   - blocked/recovery/filler are never counted as healthy throughput — they
 *     feed the drift signals as cost, not progress.
 *
 * Signals (all seams over history; every external fact overridable via $input):
 *   - repair_required_rate           share of cycles that needed repair
 *   - blocked_rate                   share of cycles that ended blocked
 *   - test_duration_trend            slope of test wall-clock over the window
 *   - repeated_subsystem_churn       a single subsystem touched again and again
 *   - revert_rollback_count          reverts / rollbacks in the window
 *   - complexity_duplication_trend   slope of complexity+duplication (when available)
 *   - evidence_completeness          share of cycles with complete evidence
 *   - operator_intervention_frequency operator interventions per cycle
 *
 * Contract: AP-809; AP-810 build contract slice LHL-12.
 * Reference shape: LoopPreflightCycleFirewallService (report shape, hashing).
 */
final class LoopQualityDriftDetectorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_quality_drift.v1';

    public const STATUS_STABLE = 'stable';

    public const STATUS_DRIFTING = 'drifting';

    public const STATUS_DEGRADED = 'degraded';

    /** Recommended de-risking actions when drifting/degraded (AP-809). */
    public const ACTION_REDUCE_AUTONOMY_TIER = 'reduce_autonomy_tier';

    public const ACTION_PAUSE_HIGH_RISK_PACKETS = 'pause_high_risk_packets';

    public const ACTION_REQUEST_ARCH_OR_SECURITY_REVIEW = 'request_architecture_or_security_review';

    public const ACTION_GENERATE_REPAIR_BACKLOG = 'generate_repair_backlog';

    public const ACTION_STOP_LONG_RUN_PROMOTION = 'stop_long_run_promotion';

    public const ROOT_CAUSE_PROVIDER_TIMEOUT = 'provider_timeout';

    public const ROOT_CAUSE_SCOPE_VIOLATION = 'scope_violation';

    public const ROOT_CAUSE_VALIDATION_FAILED = 'validation_failed';

    public const ROOT_CAUSE_JUDGE_REJECT = 'judge_reject';

    /** The eight drift signals (stable order => stable hash + stable surface). */
    private const SIGNALS = [
        'repair_required_rate',
        'blocked_rate',
        'test_duration_trend',
        'repeated_subsystem_churn',
        'revert_rollback_count',
        'complexity_duplication_trend',
        'evidence_completeness',
        'operator_intervention_frequency',
    ];

    /** Canonical root-cause kind per drift signal (first bounded debug contract). */
    private const ROOT_CAUSE_BY_SIGNAL = [
        'repair_required_rate' => self::ROOT_CAUSE_VALIDATION_FAILED,
        'blocked_rate' => self::ROOT_CAUSE_VALIDATION_FAILED,
        'test_duration_trend' => self::ROOT_CAUSE_PROVIDER_TIMEOUT,
        'repeated_subsystem_churn' => self::ROOT_CAUSE_SCOPE_VIOLATION,
        'revert_rollback_count' => self::ROOT_CAUSE_VALIDATION_FAILED,
        'complexity_duplication_trend' => self::ROOT_CAUSE_SCOPE_VIOLATION,
        'evidence_completeness' => self::ROOT_CAUSE_JUDGE_REJECT,
        'operator_intervention_frequency' => self::ROOT_CAUSE_JUDGE_REJECT,
    ];

    // ---- Drift thresholds. A signal scores 0 (ok) / 1 (warn) / 2 (alarm). ----

    /** A cycle window smaller than this is too thin to certify `stable`. */
    private const MIN_WINDOW_FOR_STABLE = 3;

    private const REPAIR_RATE_WARN = 0.20;

    private const REPAIR_RATE_ALARM = 0.40;

    private const BLOCKED_RATE_WARN = 0.25;

    private const BLOCKED_RATE_ALARM = 0.50;

    /** Test wall-clock slope as a fraction of the window mean (slower = worse). */
    private const TEST_TREND_WARN = 0.15;

    private const TEST_TREND_ALARM = 0.40;

    private const SUBSYSTEM_CHURN_WARN = 3;

    private const SUBSYSTEM_CHURN_ALARM = 5;

    private const REVERT_WARN = 1;

    private const REVERT_ALARM = 3;

    /** Complexity+duplication slope as a fraction of the window mean (rising = worse). */
    private const COMPLEXITY_TREND_WARN = 0.10;

    private const COMPLEXITY_TREND_ALARM = 0.30;

    /** Evidence completeness is a "higher is better" signal (share 0..1). */
    private const EVIDENCE_COMPLETE_WARN = 0.85;

    private const EVIDENCE_COMPLETE_ALARM = 0.60;

    private const OPERATOR_INTERVENTION_WARN = 0.20;

    private const OPERATOR_INTERVENTION_ALARM = 0.45;

    /** Aggregate drift score thresholds (sum of per-signal scores). */
    private const SCORE_DRIFTING = 2;

    private const SCORE_DEGRADED = 5;

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes
     * an empty history as `stable` (no evidence of drift) and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function detect(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry a whole drift
        // record; fold it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));

        $history = $this->normalizeHistory($input['history'] ?? ($input['cycles'] ?? []));
        $windowSize = count($history);

        $warnings = [];

        // Each signal returns ['value' => mixed, 'score' => 0|1|2, 'note' => string].
        /** @var array<string,array{value:mixed,score:int,note:string,unavailable?:bool}> $signals */
        $signals = [
            'repair_required_rate' => $this->signalRepairRequiredRate($input, $history),
            'blocked_rate' => $this->signalBlockedRate($input, $history),
            'test_duration_trend' => $this->signalTestDurationTrend($input, $history),
            'repeated_subsystem_churn' => $this->signalRepeatedSubsystemChurn($input, $history),
            'revert_rollback_count' => $this->signalRevertRollbackCount($input, $history),
            'complexity_duplication_trend' => $this->signalComplexityDuplicationTrend($input, $history),
            'evidence_completeness' => $this->signalEvidenceCompleteness($input, $history),
            'operator_intervention_frequency' => $this->signalOperatorInterventionFrequency($input, $history),
        ];

        $driftScore = 0;
        $alarmSignals = [];
        $warnSignals = [];
        foreach (self::SIGNALS as $key) {
            $score = (int) ($signals[$key]['score'] ?? 0);
            $driftScore += $score;
            if ($score >= 2) {
                $alarmSignals[] = $key;
            } elseif ($score === 1) {
                $warnSignals[] = $key;
            }
            if (! empty($signals[$key]['unavailable'])) {
                $warnings[] = 'signal_unavailable:'.$key;
            }
        }

        $status = $this->resolveStatus($driftScore, $windowSize, $alarmSignals, $warnings);

        $recommendedActions = $this->recommendActions($status, $signals, $alarmSignals, $warnSignals);
        $rootCauseClassifications = $this->classifyRootCauses($signals, $alarmSignals, $warnSignals);

        // HARD INVARIANT: a degraded loop is NEVER promoted; a drifting loop with
        // any alarm-level signal also stops promotion. stop_promotion can only be
        // false when the loop is stable (or drifting on warnings alone).
        $stopPromotion = $status === self::STATUS_DEGRADED
            || ($status === self::STATUS_DRIFTING && $alarmSignals !== []);

        if ($stopPromotion && ! in_array(self::ACTION_STOP_LONG_RUN_PROMOTION, $recommendedActions, true)) {
            $recommendedActions[] = self::ACTION_STOP_LONG_RUN_PROMOTION;
        }

        // Public signals surface: value + score + note (stable key order).
        $signalsOut = [];
        foreach (self::SIGNALS as $key) {
            $signalsOut[$key] = [
                'value' => $signals[$key]['value'],
                'score' => (int) $signals[$key]['score'],
                'level' => $this->levelLabel((int) $signals[$key]['score']),
                'note' => (string) $signals[$key]['note'],
            ];
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-809',
            'slice_id' => 'LHL-12',
            'status' => $status,
            'drift_id' => 'lqd_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $windowSize,
                $driftScore,
            ]), 0, 16),
            'run_id' => $runId,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'window_size' => $windowSize,
            'drift_score' => $driftScore,
            'alarm_signals' => array_values($alarmSignals),
            'warn_signals' => array_values($warnSignals),
            'signals' => $signalsOut,
            'root_cause_schema' => 'atlas.software_company_stewardship.loop_quality_drift.root_cause.v1',
            'root_cause_classifications' => $rootCauseClassifications,
            'root_cause_kinds' => array_values(array_unique(array_column($rootCauseClassifications, 'kind'))),
            'recommended_actions' => array_values(array_unique($recommendedActions)),
            'stop_promotion' => $stopPromotion,
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $this->nextAction($status),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- signals

    /**
     * Share of cycles that needed repair. Direct seam wins; otherwise derived
     * from history (repair_required / repaired flags, or status=='repair').
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalRepairRequiredRate(array $input, array $history): array
    {
        [$rate, $derived] = $this->rateSeam(
            $input,
            'repair_required_rate',
            $history,
            fn (array $cycle): bool => (bool) ($cycle['repair_required'] ?? false)
                || (bool) ($cycle['repaired'] ?? false)
                || in_array($this->cycleStatus($cycle), ['repair', 'repair_required', 'needs_repair'], true),
        );

        if ($rate === null) {
            return ['value' => null, 'score' => 0, 'note' => 'no repair signal', 'unavailable' => true];
        }

        $score = $this->scoreHigherWorse($rate, self::REPAIR_RATE_WARN, self::REPAIR_RATE_ALARM);

        return [
            'value' => $this->round($rate),
            'score' => $score,
            'note' => ($derived ? 'derived' : 'override').' repair_required_rate='.$this->round($rate),
        ];
    }

    /**
     * Share of cycles that ended blocked. Blocked is COST, never throughput.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalBlockedRate(array $input, array $history): array
    {
        [$rate, $derived] = $this->rateSeam(
            $input,
            'blocked_rate',
            $history,
            fn (array $cycle): bool => (bool) ($cycle['blocked'] ?? false)
                || in_array($this->cycleStatus($cycle), ['block', 'blocked'], true),
        );

        if ($rate === null) {
            return ['value' => null, 'score' => 0, 'note' => 'no blocked signal', 'unavailable' => true];
        }

        $score = $this->scoreHigherWorse($rate, self::BLOCKED_RATE_WARN, self::BLOCKED_RATE_ALARM);

        return [
            'value' => $this->round($rate),
            'score' => $score,
            'note' => ($derived ? 'derived' : 'override').' blocked_rate='.$this->round($rate),
        ];
    }

    /**
     * Trend of test wall-clock over the window, normalized by the window mean.
     * Positive = tests getting slower (worse). Direct seam wins.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalTestDurationTrend(array $input, array $history): array
    {
        if (array_key_exists('test_duration_trend', $input) && is_numeric($input['test_duration_trend'])) {
            $trend = (float) $input['test_duration_trend'];
            $score = $this->scoreHigherWorse($trend, self::TEST_TREND_WARN, self::TEST_TREND_ALARM);

            return ['value' => $this->round($trend), 'score' => $score, 'note' => 'override test_duration_trend='.$this->round($trend)];
        }

        $series = $this->numericSeries($history, ['test_duration_seconds', 'test_duration', 'tests_duration_seconds']);
        if (count($series) < 2) {
            return ['value' => null, 'score' => 0, 'note' => 'insufficient test duration samples', 'unavailable' => true];
        }

        $trend = $this->normalizedSlope($series);
        $score = $this->scoreHigherWorse($trend, self::TEST_TREND_WARN, self::TEST_TREND_ALARM);

        return ['value' => $this->round($trend), 'score' => $score, 'note' => 'derived test_duration_trend='.$this->round($trend)];
    }

    /**
     * The single most-churned subsystem in the window. A subsystem touched many
     * times without closing the finding is a drift signal (repeated_subsystem_churn).
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalRepeatedSubsystemChurn(array $input, array $history): array
    {
        if (array_key_exists('repeated_subsystem_churn', $input) && is_numeric($input['repeated_subsystem_churn'])) {
            $max = (int) $input['repeated_subsystem_churn'];
            $score = $this->scoreCountHigherWorse($max, self::SUBSYSTEM_CHURN_WARN, self::SUBSYSTEM_CHURN_ALARM);

            return ['value' => $max, 'score' => $score, 'note' => 'override repeated_subsystem_churn='.$max];
        }

        $counts = [];
        foreach ($history as $cycle) {
            $subsystem = trim((string) ($cycle['subsystem'] ?? ($cycle['area_focus'] ?? ($cycle['module'] ?? ''))));
            if ($subsystem === '') {
                continue;
            }
            $counts[$subsystem] = ($counts[$subsystem] ?? 0) + 1;
        }

        if ($counts === []) {
            return ['value' => null, 'score' => 0, 'note' => 'no subsystem signal', 'unavailable' => true];
        }

        $max = max($counts);
        $hot = array_keys($counts, $max, true)[0] ?? '';
        $score = $this->scoreCountHigherWorse($max, self::SUBSYSTEM_CHURN_WARN, self::SUBSYSTEM_CHURN_ALARM);

        return [
            'value' => $max,
            'score' => $score,
            'note' => 'derived repeated_subsystem_churn='.$max.' subsystem='.$hot,
        ];
    }

    /**
     * Revert / rollback count in the window. Reverts undo prior "progress" and
     * are a strong degradation signal.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalRevertRollbackCount(array $input, array $history): array
    {
        if (array_key_exists('revert_rollback_count', $input) && is_numeric($input['revert_rollback_count'])) {
            $count = max(0, (int) $input['revert_rollback_count']);
            $score = $this->scoreCountHigherWorse($count, self::REVERT_WARN, self::REVERT_ALARM);

            return ['value' => $count, 'score' => $score, 'note' => 'override revert_rollback_count='.$count];
        }

        $count = 0;
        foreach ($history as $cycle) {
            if ((bool) ($cycle['reverted'] ?? false)
                || (bool) ($cycle['rolled_back'] ?? false)
                || in_array($this->cycleStatus($cycle), ['revert', 'reverted', 'rollback', 'rolled_back'], true)) {
                $count++;
            }
            $count += max(0, (int) ($cycle['revert_count'] ?? 0));
        }

        return ['value' => $count, 'score' => $this->scoreCountHigherWorse($count, self::REVERT_WARN, self::REVERT_ALARM), 'note' => 'derived revert_rollback_count='.$count];
    }

    /**
     * Trend of complexity + duplication over the window (when available),
     * normalized by the window mean. Positive = rising (worse).
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalComplexityDuplicationTrend(array $input, array $history): array
    {
        if (array_key_exists('complexity_duplication_trend', $input) && is_numeric($input['complexity_duplication_trend'])) {
            $trend = (float) $input['complexity_duplication_trend'];
            $score = $this->scoreHigherWorse($trend, self::COMPLEXITY_TREND_WARN, self::COMPLEXITY_TREND_ALARM);

            return ['value' => $this->round($trend), 'score' => $score, 'note' => 'override complexity_duplication_trend='.$this->round($trend)];
        }

        // Combine complexity + duplication per cycle when present.
        $series = [];
        foreach ($history as $cycle) {
            $complexity = $this->firstNumeric($cycle, ['complexity', 'complexity_score', 'cyclomatic']);
            $duplication = $this->firstNumeric($cycle, ['duplication', 'duplication_score', 'duplicate_ratio']);
            if ($complexity === null && $duplication === null) {
                continue;
            }
            $series[] = ($complexity ?? 0.0) + ($duplication ?? 0.0);
        }

        if (count($series) < 2) {
            // Genuinely "when available" — absence is not drift.
            return ['value' => null, 'score' => 0, 'note' => 'complexity/duplication not available', 'unavailable' => true];
        }

        $trend = $this->normalizedSlope($series);
        $score = $this->scoreHigherWorse($trend, self::COMPLEXITY_TREND_WARN, self::COMPLEXITY_TREND_ALARM);

        return ['value' => $this->round($trend), 'score' => $score, 'note' => 'derived complexity_duplication_trend='.$this->round($trend)];
    }

    /**
     * Share of cycles with COMPLETE evidence. This is a "higher is better"
     * signal: low completeness is drift.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalEvidenceCompleteness(array $input, array $history): array
    {
        if (array_key_exists('evidence_completeness', $input) && is_numeric($input['evidence_completeness'])) {
            $rate = $this->clamp01((float) $input['evidence_completeness']);
            $score = $this->scoreLowerWorse($rate, self::EVIDENCE_COMPLETE_WARN, self::EVIDENCE_COMPLETE_ALARM);

            return ['value' => $this->round($rate), 'score' => $score, 'note' => 'override evidence_completeness='.$this->round($rate)];
        }

        if ($history === []) {
            return ['value' => null, 'score' => 0, 'note' => 'no evidence signal', 'unavailable' => true];
        }

        $complete = 0;
        $counted = 0;
        foreach ($history as $cycle) {
            if (! array_key_exists('evidence_complete', $cycle)
                && ! array_key_exists('evidence_completeness', $cycle)
                && ! array_key_exists('evidence_missing', $cycle)) {
                continue;
            }
            $counted++;
            $isComplete = array_key_exists('evidence_complete', $cycle)
                ? (bool) $cycle['evidence_complete']
                : (array_key_exists('evidence_completeness', $cycle)
                    ? ((float) $cycle['evidence_completeness']) >= 1.0
                    : ! (bool) ($cycle['evidence_missing'] ?? false));
            if ($isComplete) {
                $complete++;
            }
        }

        if ($counted === 0) {
            return ['value' => null, 'score' => 0, 'note' => 'no evidence signal', 'unavailable' => true];
        }

        $rate = $this->clamp01($complete / $counted);
        $score = $this->scoreLowerWorse($rate, self::EVIDENCE_COMPLETE_WARN, self::EVIDENCE_COMPLETE_ALARM);

        return ['value' => $this->round($rate), 'score' => $score, 'note' => 'derived evidence_completeness='.$this->round($rate)];
    }

    /**
     * Operator interventions per cycle. More hand-holding = more drift.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @return array{value:mixed,score:int,note:string,unavailable?:bool}
     */
    private function signalOperatorInterventionFrequency(array $input, array $history): array
    {
        if (array_key_exists('operator_intervention_frequency', $input) && is_numeric($input['operator_intervention_frequency'])) {
            $freq = max(0.0, (float) $input['operator_intervention_frequency']);
            $score = $this->scoreHigherWorse($freq, self::OPERATOR_INTERVENTION_WARN, self::OPERATOR_INTERVENTION_ALARM);

            return ['value' => $this->round($freq), 'score' => $score, 'note' => 'override operator_intervention_frequency='.$this->round($freq)];
        }

        if ($history === []) {
            return ['value' => null, 'score' => 0, 'note' => 'no operator intervention signal', 'unavailable' => true];
        }

        $interventions = 0;
        foreach ($history as $cycle) {
            if ((bool) ($cycle['operator_intervened'] ?? false) || (bool) ($cycle['operator_intervention'] ?? false)) {
                $interventions++;
            }
            $interventions += max(0, (int) ($cycle['operator_interventions'] ?? 0));
        }

        $freq = $interventions / max(1, count($history));
        $score = $this->scoreHigherWorse($freq, self::OPERATOR_INTERVENTION_WARN, self::OPERATOR_INTERVENTION_ALARM);

        return ['value' => $this->round($freq), 'score' => $score, 'note' => 'derived operator_intervention_frequency='.$this->round($freq)];
    }

    // ---------------------------------------------------------------- scoring

    /**
     * Resolve the overall status from the aggregate drift score. An alarm-level
     * signal forces at least `drifting`; the degraded threshold (or two+ alarms)
     * forces `degraded`. A too-thin window can never certify `stable`.
     *
     * @param  list<string>  $alarmSignals
     * @param  list<string>  $warnings
     */
    private function resolveStatus(int $driftScore, int $windowSize, array $alarmSignals, array &$warnings): string
    {
        if ($driftScore >= self::SCORE_DEGRADED || count($alarmSignals) >= 2) {
            return self::STATUS_DEGRADED;
        }

        if ($driftScore >= self::SCORE_DRIFTING || $alarmSignals !== []) {
            return self::STATUS_DRIFTING;
        }

        // No drift detected. Only certify `stable` with enough history; a thin
        // window is reported as drifting-on-insufficient-evidence, never a
        // false `stable`.
        if ($windowSize > 0 && $windowSize < self::MIN_WINDOW_FOR_STABLE) {
            $warnings[] = 'insufficient_history_for_stable';

            return self::STATUS_DRIFTING;
        }

        return self::STATUS_STABLE;
    }

    /**
     * Map status + which signals fired to a de-risking action plan.
     *
     * @param  array<string,array{value:mixed,score:int,note:string,unavailable?:bool}>  $signals
     * @param  list<string>  $alarmSignals
     * @param  list<string>  $warnSignals
     * @return list<string>
     */
    private function recommendActions(string $status, array $signals, array $alarmSignals, array $warnSignals): array
    {
        if ($status === self::STATUS_STABLE) {
            return [];
        }

        $actions = [];

        // Drifting/degraded both reduce autonomy and stop promotion of a longer run.
        $actions[] = self::ACTION_REDUCE_AUTONOMY_TIER;
        $actions[] = self::ACTION_STOP_LONG_RUN_PROMOTION;

        if ($status === self::STATUS_DEGRADED) {
            $actions[] = self::ACTION_PAUSE_HIGH_RISK_PACKETS;
            $actions[] = self::ACTION_GENERATE_REPAIR_BACKLOG;
        }

        $fired = array_merge($alarmSignals, $warnSignals);

        // High repair / blocked / revert => repair backlog.
        if (array_intersect(['repair_required_rate', 'blocked_rate', 'revert_rollback_count'], $fired) !== []) {
            $actions[] = self::ACTION_GENERATE_REPAIR_BACKLOG;
        }

        // Rising complexity/duplication or churning a single subsystem => arch/security review.
        if (array_intersect(['complexity_duplication_trend', 'repeated_subsystem_churn'], $fired) !== []) {
            $actions[] = self::ACTION_REQUEST_ARCH_OR_SECURITY_REVIEW;
        }

        // Any alarm-level signal pauses high-risk packets.
        if ($alarmSignals !== []) {
            $actions[] = self::ACTION_PAUSE_HIGH_RISK_PACKETS;
        }

        return array_values(array_unique($actions));
    }

    /**
     * Classify each fired drift signal into a canonical debug root-cause kind so
     * the next cycle can target repair without inventing a fresh taxonomy.
     *
     * @param  array<string,array{value:mixed,score:int,note:string,unavailable?:bool}>  $signals
     * @param  list<string>  $alarmSignals
     * @param  list<string>  $warnSignals
     * @return list<array{signal:string,kind:string,level:string,score:int,rationale:string}>
     */
    private function classifyRootCauses(array $signals, array $alarmSignals, array $warnSignals): array
    {
        $fired = array_values(array_unique(array_merge($alarmSignals, $warnSignals)));
        $classifications = [];

        foreach (self::SIGNALS as $signal) {
            if (! in_array($signal, $fired, true)) {
                continue;
            }

            $score = (int) ($signals[$signal]['score'] ?? 0);
            $classifications[] = [
                'signal' => $signal,
                'kind' => self::ROOT_CAUSE_BY_SIGNAL[$signal],
                'level' => $this->levelLabel($score),
                'score' => $score,
                'rationale' => $this->rootCauseRationale($signal),
            ];
        }

        return $classifications;
    }

    private function rootCauseRationale(string $signal): string
    {
        return match ($signal) {
            'repair_required_rate', 'blocked_rate', 'revert_rollback_count' => 'loop output failed validation or required repair before it could count as healthy throughput',
            'test_duration_trend' => 'test wall-clock drift points at provider or execution timeout pressure',
            'repeated_subsystem_churn', 'complexity_duplication_trend' => 'localized churn or structural growth points at scope control failure',
            'evidence_completeness', 'operator_intervention_frequency' => 'missing evidence or operator hand-off points at judge rejection pressure',
            default => 'drift signal fired',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            self::STATUS_DEGRADED => 'stop_long_run_promotion',
            self::STATUS_DRIFTING => 'reduce_autonomy_tier',
            default => 'continue',
        };
    }

    /** Score a "higher is worse" numeric value against warn/alarm thresholds. */
    private function scoreHigherWorse(float $value, float $warn, float $alarm): int
    {
        if ($value >= $alarm) {
            return 2;
        }
        if ($value >= $warn) {
            return 1;
        }

        return 0;
    }

    /** Score a "lower is worse" numeric value (e.g. completeness). */
    private function scoreLowerWorse(float $value, float $warn, float $alarm): int
    {
        if ($value <= $alarm) {
            return 2;
        }
        if ($value <= $warn) {
            return 1;
        }

        return 0;
    }

    /** Score a "higher is worse" integer count against warn/alarm thresholds. */
    private function scoreCountHigherWorse(int $value, int $warn, int $alarm): int
    {
        if ($value >= $alarm) {
            return 2;
        }
        if ($value >= $warn) {
            return 1;
        }

        return 0;
    }

    private function levelLabel(int $score): string
    {
        return match ($score) {
            2 => 'alarm',
            1 => 'warn',
            default => 'ok',
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve a rate either from a direct override seam or by deriving it from
     * history with a per-cycle predicate.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $history
     * @param  callable(array<string,mixed>):bool  $predicate
     * @return array{0:float|null,1:bool}  [rate|null, derivedFlag]
     */
    private function rateSeam(array $input, string $key, array $history, callable $predicate): array
    {
        if (array_key_exists($key, $input) && is_numeric($input[$key])) {
            return [$this->clamp01((float) $input[$key]), false];
        }

        if ($history === []) {
            return [null, true];
        }

        $hits = 0;
        foreach ($history as $cycle) {
            if ($predicate($cycle)) {
                $hits++;
            }
        }

        return [$this->clamp01($hits / max(1, count($history))), true];
    }

    /**
     * Normalize an arbitrary history value into a stable, sorted-by-index list
     * of cycle records.
     *
     * @return list<array<string,mixed>>
     */
    private function normalizeHistory(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        // Order by an explicit index/cycle marker when present so the slope/trend
        // computations are deterministic regardless of input ordering.
        usort($rows, function (array $a, array $b): int {
            $ia = $this->cycleOrder($a);
            $ib = $this->cycleOrder($b);

            return $ia <=> $ib;
        });

        return array_values($rows);
    }

    /** @param  array<string,mixed>  $cycle */
    private function cycleOrder(array $cycle): int
    {
        foreach (['cycle_index', 'index', 'cycle', 'sequence', 'seq'] as $k) {
            if (array_key_exists($k, $cycle) && is_numeric($cycle[$k])) {
                return (int) $cycle[$k];
            }
        }

        return PHP_INT_MAX;
    }

    /** @param  array<string,mixed>  $cycle */
    private function cycleStatus(array $cycle): string
    {
        return strtolower(trim((string) ($cycle['status'] ?? ($cycle['outcome'] ?? ($cycle['result'] ?? '')))));
    }

    /**
     * Extract a numeric series across the window for any of the given keys
     * (first present key per cycle wins).
     *
     * @param  list<array<string,mixed>>  $history
     * @param  list<string>  $keys
     * @return list<float>
     */
    private function numericSeries(array $history, array $keys): array
    {
        $series = [];
        foreach ($history as $cycle) {
            $value = $this->firstNumeric($cycle, $keys);
            if ($value !== null) {
                $series[] = $value;
            }
        }

        return $series;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  list<string>  $keys
     */
    private function firstNumeric(array $cycle, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $cycle) && is_numeric($cycle[$key])) {
                return (float) $cycle[$key];
            }
        }

        return null;
    }

    /**
     * Least-squares slope of a series normalized by the series mean, so the
     * trend is a unitless fraction (e.g. 0.20 = ~20% rise across the window).
     *
     * @param  list<float>  $series
     */
    private function normalizedSlope(array $series): float
    {
        $n = count($series);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($series as $i => $y) {
            $x = (float) $i;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) {
            return 0.0;
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $mean = $sumY / $n;
        if ($mean == 0.0) {
            return 0.0;
        }

        // Slope-per-step normalized by the mean; positive => rising.
        return $slope / abs($mean);
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }

    /**
     * A wiring-phase `fixture` may be a single drift record; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}

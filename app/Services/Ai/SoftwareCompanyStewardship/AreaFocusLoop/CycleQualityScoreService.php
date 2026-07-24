<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Support\Clamp01;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * AP-810 / LHL-11 — Cycle Quality Score.
 *
 * Read-only, deterministic, input-seam driven. Answers a single question about
 * one completed loop cycle: was this VALUABLE engineering progress, or merely a
 * VALID merge? It distinguishes a "salto" (a leap that compounds context, memory,
 * quality, agents, speed or robustness) from a trivial green-but-empty cycle.
 *
 * It never runs the loop, never invokes a provider, never merges, never deletes a
 * branch. It composes per-cycle signals (passed via input seams; fixtures in tests)
 * into a 0..1 quality score, a band (high|medium|low), a quality-floor verdict and
 * an explicit `counts_as_leap` boolean.
 *
 * Honesty rules (operator does not accept false claims, AP-807 hard invariants):
 *   - a BLOCKED cycle is never a leap and scores 0 (blocked != success);
 *   - a sandbox-only commit is never a merge, so it cannot count as progress;
 *   - a plan-only / fixture Forge run is never an implementation;
 *   - recovery / missing-test filler is never productivity;
 *   - a trivial valid merge (e.g. a single missing-test addition with no blocker
 *     reduced and no packet progression) scores LOW and `counts_as_leap=false`,
 *     even though the merge itself was valid.
 *
 * Contract: storage/atlas/tmp/ap810-build-contract.md (LHL-11);
 * docs/ap/AP-810-long-horizon-loop-enterprise-delivery-block-contract.md.
 */
final class CycleQualityScoreService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_cycle_quality.v1';

    public const STATUS_HIGH = 'high';

    public const STATUS_MEDIUM = 'medium';

    public const STATUS_LOW = 'low';

    /** Default quality floor a cycle must reach to "meet" the bar (overridable via input `floor`). */
    public const DEFAULT_FLOOR = 0.5;

    /** Score >= this band threshold => high. */
    private const HIGH_BAND = 0.66;

    /** Score >= this band threshold (and below HIGH_BAND) => medium; below => low. */
    private const MEDIUM_BAND = 0.4;

    /**
     * Weighted quality signals. Weights are deterministic and sum to 1.0. Every
     * signal is an input seam (fixture in tests); absent => 0 (neutral / no value).
     *
     * @var array<string,float>
     */
    private const SIGNAL_WEIGHTS = [
        'blocker_reduced' => 0.22,
        'packet_progression_advanced' => 0.22,
        'aaeos_relevance' => 0.10,
        'factory_evolution_relevance' => 0.08,
        'test_evidence_quality' => 0.13,
        'code_churn' => 0.05,
        'absence_of_filler' => 0.10,
        'compounding_impact' => 0.10,
    ];

    /** The six compounding axes (LHL-11 "effect on ..."). */
    private const IMPACT_AXES = ['context', 'memory', 'quality', 'agents', 'speed', 'robustness'];

    /**
     * Score one completed loop cycle.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function score(array $input = []): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $cycle = $this->cycleInput($input);

        $blockers = [];
        $warnings = [];

        $floor = $this->floor($input);

        // ---- honesty gates (negative invariants) determine real productivity ----
        $blocked = (bool) ($cycle['blocked'] ?? false);
        $mergePerformed = (bool) ($cycle['merge_performed'] ?? false);
        $sandboxCommitOnly = (bool) ($cycle['sandbox_commit_only'] ?? false);
        $forgePlanOnly = (bool) ($cycle['forge_plan_only'] ?? false);
        $isFiller = (bool) ($cycle['is_filler'] ?? false)
            || (bool) ($cycle['is_recovery'] ?? false)
            || (bool) ($cycle['missing_test_filler'] ?? false);

        // A cycle represents REAL productivity only if it was not blocked, it
        // actually merged, it was not a sandbox-only commit, and it was not a
        // plan-only Forge run. Anything else is, by contract, NOT progress.
        $realProductiveMerge = ! $blocked && $mergePerformed && ! $sandboxCommitOnly && ! $forgePlanOnly;

        if ($blocked) {
            $blockers[] = 'blocked_cycle_is_not_productivity';
        }
        if ($sandboxCommitOnly) {
            $blockers[] = 'sandbox_commit_is_not_merge';
        }
        if ($forgePlanOnly) {
            $blockers[] = 'forge_plan_only_is_not_implementation';
        }
        if ($isFiller) {
            $warnings[] = 'recovery_or_filler_is_not_productivity';
        }
        if (! $blocked && ! $mergePerformed && ! $sandboxCommitOnly && ! $forgePlanOnly) {
            // Not blocked, but nothing merged either — no value to score.
            $warnings[] = 'cycle_did_not_merge';
        }

        // ---- signal scoring ----
        $signals = $this->signalScores($cycle, $isFiller);
        $impact = $this->impactAxes($cycle);
        $compounding = $this->compoundingImpact($impact);
        $signals['compounding_impact'] = $compounding;

        // Weighted sum (each signal already clamped to 0..1).
        $rawScore = 0.0;
        foreach (self::SIGNAL_WEIGHTS as $signal => $weight) {
            $rawScore += $weight * (float) ($signals[$signal] ?? 0.0);
        }

        // Hard zero when the cycle was not real productive engineering: a blocked,
        // sandbox-only, or plan-only Forge cycle cannot be a quality leap. This is
        // the negative invariant — blocked/sandbox/plan-only is never dressed up.
        $score = $realProductiveMerge ? $this->round($rawScore) : 0.0;

        // A "leap" (salto) requires: real productive merge AND a meaningful score
        // AND at least one of {blocker reduced, packet progression advanced}. A
        // trivial valid merge (missing-test filler only) reduces no blocker and
        // advances no packet => never a leap.
        $hasMeaningfulMovement = ((float) ($signals['blocker_reduced'] ?? 0.0)) > 0.0
            || ((float) ($signals['packet_progression_advanced'] ?? 0.0)) > 0.0;
        $countsAsLeap = $realProductiveMerge
            && ! $isFiller
            && $score >= self::HIGH_BAND
            && $hasMeaningfulMovement;

        $status = $this->band($score);
        $meetsFloor = $score >= $floor && $realProductiveMerge;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-810',
            'slice_id' => 'LHL-11',
            'status' => $status,
            'quality_id' => 'cqs_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                (string) ($cycle['run_id'] ?? ''),
                (int) ($cycle['cycle_index'] ?? 0),
                (string) ($cycle['finding_id'] ?? ''),
                (string) ($cycle['packet_id'] ?? ''),
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => AreaFocusUtcClock::atomNow(),
            'cycle_ref' => [
                'run_id' => (string) ($cycle['run_id'] ?? ''),
                'cycle_index' => (int) ($cycle['cycle_index'] ?? 0),
                'finding_id' => (string) ($cycle['finding_id'] ?? ''),
                'packet_id' => (string) ($cycle['packet_id'] ?? ''),
            ],
            'quality_score' => $score,
            'band' => $status,
            'floor' => $floor,
            'meets_quality_floor' => $meetsFloor,
            'counts_as_leap' => $countsAsLeap,
            'real_productive_merge' => $realProductiveMerge,
            'signals' => $signals,
            'signal_weights' => self::SIGNAL_WEIGHTS,
            'impact_axes' => $impact,
            'compounding_impact' => $compounding,
            'honesty' => [
                'blocked' => $blocked,
                'merge_performed' => $mergePerformed,
                'sandbox_commit_only' => $sandboxCommitOnly,
                'forge_plan_only' => $forgePlanOnly,
                'is_filler_or_recovery' => $isFiller,
            ],
            'value_summary' => $this->valueSummary($score, $countsAsLeap, $realProductiveMerge, $impact),
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'warnings' => AreaFocusStringListNormalizer::uniqueStringValues($warnings),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
                'valid_merge_is_not_automatically_a_leap' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileReportFields($payload));

        return $payload;
    }

    // ---------- scoring helpers ----------

    /**
     * Per-signal 0..1 scores. Boolean seams map to 0.0/1.0; numeric seams are
     * clamped to 0..1. Absent => 0.0 (no value claimed).
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,float>
     */
    private function signalScores(array $cycle, bool $isFiller): array
    {
        // absence_of_filler is the inverse of the filler/recovery flags, but an
        // explicit seam (if supplied) wins.
        $absenceOfFiller = array_key_exists('absence_of_filler', $cycle)
            ? $this->clamp01($cycle['absence_of_filler'])
            : ($isFiller ? 0.0 : 1.0);

        return [
            'blocker_reduced' => $this->signal($cycle, 'blocker_reduced'),
            'packet_progression_advanced' => $this->signal($cycle, 'packet_progression_advanced'),
            'aaeos_relevance' => $this->signal($cycle, 'aaeos_relevance'),
            'factory_evolution_relevance' => $this->signal($cycle, 'factory_evolution_relevance'),
            'test_evidence_quality' => $this->signal($cycle, 'test_evidence_quality'),
            'code_churn' => $this->churnScore($cycle),
            'absence_of_filler' => $this->round($absenceOfFiller),
        ];
    }

    /**
     * Map a single signal seam to 0..1. Accepts bool or numeric.
     *
     * @param  array<string,mixed>  $cycle
     */
    private function signal(array $cycle, string $key): float
    {
        if (! array_key_exists($key, $cycle)) {
            return 0.0;
        }

        return $this->round($this->clamp01($cycle[$key]));
    }

    /**
     * Code churn is a quality signal where MODERATE churn is best: zero churn means
     * nothing changed, huge churn means a sprawling risky change. We accept either a
     * pre-computed `code_churn` quality seam (0..1) or derive a quality from a raw
     * `code_churn_files` count (small/bounded change scores high).
     *
     * @param  array<string,mixed>  $cycle
     */
    private function churnScore(array $cycle): float
    {
        if (array_key_exists('code_churn', $cycle)) {
            return $this->round($this->clamp01($cycle['code_churn']));
        }
        if (! array_key_exists('code_churn_files', $cycle)) {
            return 0.0;
        }

        $files = (int) $cycle['code_churn_files'];
        if ($files <= 0) {
            return 0.0;
        }
        // 1..3 files => bounded/ideal (1.0); grows to ~10 files => 0.4; >20 => 0.1.
        if ($files <= 3) {
            return 1.0;
        }
        if ($files <= 10) {
            return 0.6;
        }
        if ($files <= 20) {
            return 0.3;
        }

        return 0.1;
    }

    /**
     * The six compounding impact axes (context, memory, quality, agents, speed,
     * robustness), each a 0..1 seam. Absent => 0.0.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,float>
     */
    private function impactAxes(array $cycle): array
    {
        $impactInput = is_array($cycle['impact_on'] ?? null) ? $cycle['impact_on'] : [];
        $axes = [];
        foreach (self::IMPACT_AXES as $axis) {
            $axes[$axis] = array_key_exists($axis, $impactInput)
                ? $this->round($this->clamp01($impactInput[$axis]))
                : 0.0;
        }

        return $axes;
    }

    /**
     * Compounding impact = the mean of the six axes (0..1). A cycle that lifts many
     * axes is a stronger leap than one that touches a single axis.
     *
     * @param  array<string,float>  $impact
     */
    private function compoundingImpact(array $impact): float
    {
        if ($impact === []) {
            return 0.0;
        }

        return $this->round(array_sum($impact) / count($impact));
    }

    private function band(float $score): string
    {
        if ($score >= self::HIGH_BAND) {
            return self::STATUS_HIGH;
        }
        if ($score >= self::MEDIUM_BAND) {
            return self::STATUS_MEDIUM;
        }

        return self::STATUS_LOW;
    }

    /**
     * @param  array<string,float>  $impact
     */
    private function valueSummary(float $score, bool $countsAsLeap, bool $realProductiveMerge, array $impact): string
    {
        if (! $realProductiveMerge) {
            return 'no_real_productive_merge: blocked/sandbox-only/plan-only cycle contributes no engineering value';
        }
        if ($countsAsLeap) {
            $axes = array_keys(array_filter($impact, static fn (float $v): bool => $v > 0.0));

            return 'leap: high-value progress (score='.$score.') compounding '.($axes === [] ? 'no axis' : implode('+', $axes));
        }

        return 'valid_but_not_a_leap: merge was valid (score='.$score.') but did not reduce a blocker or advance a packet meaningfully';
    }

    // ---------- input helpers ----------

    /**
     * The per-cycle signal record. Accepts either a top-level `cycle` array seam or
     * a flat `fixture` array (CLI --fixture-file passes the parsed JSON here). When
     * both absent the default is an empty (no-value) cycle, never a crash.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function cycleInput(array $input): array
    {
        if (isset($input['cycle']) && is_array($input['cycle'])) {
            return $input['cycle'];
        }
        if (isset($input['fixture']) && is_array($input['fixture'])) {
            // A fixture may wrap the cycle under a `cycle` key or be the cycle itself.
            if (isset($input['fixture']['cycle']) && is_array($input['fixture']['cycle'])) {
                return $input['fixture']['cycle'];
            }

            return $input['fixture'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function floor(array $input): float
    {
        $cycle = $this->cycleInput($input);
        if (array_key_exists('floor', $input)) {
            return $this->clamp01($input['floor']);
        }
        if (array_key_exists('floor', $cycle)) {
            return $this->clamp01($cycle['floor']);
        }

        return self::DEFAULT_FLOOR;
    }

    private function clamp01(mixed $value): float
    {
        return Clamp01::fromMixed($value);
    }

    /** Round to 4 decimals so the weighted sum is deterministic across platforms. */
    private function round(float $value): float
    {
        return round($value, 4);
    }
}

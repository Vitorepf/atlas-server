<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderLearning;

/**
 * Pure scorer. Summarizes muscle worker performance from resolved event rows so Maestro
 * can route by demonstrated quality instead of raw availability.
 *
 * Penalizes fake productivity (give_backs, malformed claims, success without evidence)
 * MORE heavily than slow but clean work.
 *
 * Input events per row: { client_id, event, task_class, scope_size, cycle_time_seconds, has_required_evidence }
 * Events recognized: success, give_back, retry, failed_gate, malformed
 *
 * Pure: no I/O, no persistence, no provider calls.
 */
final class AtlasMaestroWorkerQualityScorecard
{
    public const SCHEMA = 'atlas.maestro.worker_quality_scorecard.v1';

    // Events
    public const EV_SUCCESS     = 'success';
    public const EV_GIVE_BACK   = 'give_back';
    public const EV_RETRY       = 'retry';
    public const EV_FAILED_GATE = 'failed_gate';
    public const EV_MALFORMED   = 'malformed';

    // Confidence tiers
    private const CONFIDENCE_HIGH   = 'high';
    private const CONFIDENCE_MEDIUM = 'medium';
    private const CONFIDENCE_LOW    = 'low';

    // Penalty weights — fake productivity > slow clean work.
    private const PENALTY_SUCCESS_NO_EVIDENCE   = 3.0; // heaviest: falsely claims done
    private const PENALTY_MALFORMED_EXTRA       = 1.5; // per repeated malformed (first is noise)
    private const PENALTY_GIVE_BACK_HIGH_RATE   = 2.0; // rate > 25%
    private const PENALTY_GIVE_BACK_MILD        = 0.2; // per give_back below threshold
    private const PENALTY_FAILED_GATE           = 0.5; // per gate failure
    private const PENALTY_RETRY                 = 0.2; // per retry

    private const GIVE_BACK_HIGH_RATE_THRESHOLD = 0.25;
    private const BEST_CLASS_SUCCESS_RATE       = 0.75; // >= this → best
    private const AVOID_CLASS_FAILURE_RATE      = 0.50; // >= this → avoid
    private const CONFIDENCE_HIGH_MIN_EVENTS    = 10;
    private const CONFIDENCE_MEDIUM_MIN_EVENTS  = 5;
    private const MIN_CLASS_EVENTS_FOR_ROUTING  = 2;

    /**
     * @param  array{events?: list<array<string,mixed>>}  $input
     * @return array{schema:string, workers:list<array<string,mixed>>}
     */
    public function score(array $input): array
    {
        $events = is_array($input['events'] ?? null) ? $input['events'] : [];

        // Group by client_id.
        $byWorker = [];
        foreach ($events as $row) {
            $cid = (string) ($row['client_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $byWorker[$cid][] = $row;
        }

        $workers = [];
        foreach ($byWorker as $clientId => $rows) {
            $workers[] = $this->scoreWorker($clientId, $rows);
        }

        // Sort deterministically.
        usort($workers, static fn (array $a, array $b): int => strcmp((string) $a['client_id'], (string) $b['client_id']));

        return ['schema' => self::SCHEMA, 'workers' => $workers];
    }

    /** @param  list<array<string,mixed>>  $rows */
    private function scoreWorker(string $clientId, array $rows): array
    {
        // Aggregate event counts.
        $counts = [
            self::EV_SUCCESS     => 0,
            self::EV_GIVE_BACK   => 0,
            self::EV_RETRY       => 0,
            self::EV_FAILED_GATE => 0,
            self::EV_MALFORMED   => 0,
        ];
        $cycleTimes      = [];
        $scopeSizes      = [];
        $successNoEvid   = 0;

        // Per-task-class tracking.
        $classCounts = []; // ['class']['success'|'fail'] => int

        foreach ($rows as $row) {
            $ev  = (string) ($row['event']      ?? '');
            $cls = (string) ($row['task_class'] ?? 'unknown');
            if (isset($counts[$ev])) {
                $counts[$ev]++;
            }
            $classCounts[$cls] ??= ['success' => 0, 'fail' => 0];
            if ($ev === self::EV_SUCCESS) {
                $classCounts[$cls]['success']++;
                if (! (bool) ($row['has_required_evidence'] ?? true)) {
                    $successNoEvid++;
                }
            } elseif (in_array($ev, [self::EV_GIVE_BACK, self::EV_FAILED_GATE], true)) {
                $classCounts[$cls]['fail']++;
            }
            $ct = (int) ($row['cycle_time_seconds'] ?? 0);
            if ($ct > 0) {
                $cycleTimes[] = $ct;
            }
            $ss = (int) ($row['scope_size'] ?? 0);
            if ($ss > 0) {
                $scopeSizes[] = $ss;
            }
        }

        $total = count($rows);
        $score = 10.0;
        $riskFlags = [];

        // ── Penalty: success without required evidence (heaviest — fake done) ──
        if ($successNoEvid > 0) {
            $score -= self::PENALTY_SUCCESS_NO_EVIDENCE * $successNoEvid;
            $riskFlags[] = 'success_without_evidence';
        }

        // ── Penalty: give_back rate ──────────────────────────────────────────
        if ($counts[self::EV_GIVE_BACK] > 0) {
            $gbRate = $total > 0 ? $counts[self::EV_GIVE_BACK] / $total : 0.0;
            if ($gbRate > self::GIVE_BACK_HIGH_RATE_THRESHOLD) {
                $score -= self::PENALTY_GIVE_BACK_HIGH_RATE;
                $riskFlags[] = 'high_giveback_rate';
            } else {
                $score -= self::PENALTY_GIVE_BACK_MILD * $counts[self::EV_GIVE_BACK];
            }
        }

        // ── Penalty: repeated malformed (first is noise, extra is pattern) ────
        $extraMalformed = max(0, $counts[self::EV_MALFORMED] - 1);
        if ($extraMalformed > 0) {
            $score -= self::PENALTY_MALFORMED_EXTRA * $extraMalformed;
            $riskFlags[] = 'repeated_malformed';
        }

        // ── Penalty: gate failures ────────────────────────────────────────────
        if ($counts[self::EV_FAILED_GATE] > 0) {
            $score -= self::PENALTY_FAILED_GATE * $counts[self::EV_FAILED_GATE];
        }

        // ── Penalty: retries (lightest — slow but clean) ──────────────────────
        if ($counts[self::EV_RETRY] > 0) {
            $score -= self::PENALTY_RETRY * $counts[self::EV_RETRY];
        }

        $score = max(0.0, min(10.0, round($score, 2)));

        // ── Task class routing ────────────────────────────────────────────────
        $bestClasses  = [];
        $avoidClasses = [];
        foreach ($classCounts as $cls => $cc) {
            $clsTotal = $cc['success'] + $cc['fail'];
            if ($clsTotal < self::MIN_CLASS_EVENTS_FOR_ROUTING) {
                continue;
            }
            $sr = $cc['success'] / $clsTotal;
            $fr = $cc['fail'] / $clsTotal;
            if ($sr >= self::BEST_CLASS_SUCCESS_RATE) {
                $bestClasses[] = $cls;
            }
            if ($fr >= self::AVOID_CLASS_FAILURE_RATE) {
                $avoidClasses[] = $cls;
            }
        }
        sort($bestClasses);
        sort($avoidClasses);

        // ── Confidence ───────────────────────────────────────────────────────
        $confidence = match (true) {
            $total >= self::CONFIDENCE_HIGH_MIN_EVENTS   => self::CONFIDENCE_HIGH,
            $total >= self::CONFIDENCE_MEDIUM_MIN_EVENTS => self::CONFIDENCE_MEDIUM,
            default                                       => self::CONFIDENCE_LOW,
        };

        return [
            'client_id'          => $clientId,
            'quality_score'      => $score,
            'risk_flags'         => array_values(array_unique($riskFlags)),
            'best_task_classes'  => $bestClasses,
            'avoid_task_classes' => $avoidClasses,
            'confidence'         => $confidence,
            'event_summary'      => array_merge(['total' => $total], $counts),
            'avg_cycle_time_s'   => $cycleTimes !== [] ? (int) round(array_sum($cycleTimes) / count($cycleTimes)) : null,
            'avg_scope_size'     => $scopeSizes  !== [] ? (int) round(array_sum($scopeSizes)  / count($scopeSizes))  : null,
        ];
    }
}

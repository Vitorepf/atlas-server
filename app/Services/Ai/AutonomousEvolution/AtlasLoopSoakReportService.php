<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE SOAK INSTRUMENT — the provider-free answer to "is the loop evolving WELL, or just busy?" over a rolling
 * window (default 1h). It READS what the loop already persisted; it never invokes a provider, never runs a
 * cycle, never mutates. Four dimensions, each fail-OPEN to a degraded section (a missing table / bad row
 * degrades that one signal, never throws):
 *
 *   (a) DELIVERIES   — what merged to main in the window: count, by work-type, diff size. (Activity.)
 *   (b) QUALITY      — the EXISTING honest signal {@see AtlasLoopRealWorkScorecardService}: material vs
 *                      PROXY/cosmetic ratios. proxy_alarm fires when faxina is winning. (Is it real?)
 *   (c) COMPOUNDING  — the Fibonacci proof: the CapabilityTrend slope AND whether the DARED rung-size is
 *                      CLIMBING cycle-to-cycle (the only signal that separates evolution from activity).
 *   (d) SPEND        — per-provider delivery cost + regressions the sentinel caught. (Cost + safety.)
 *
 * Pure reads over injected collaborators — deterministic, seedable, proven with phpunit + fakes (NO loop run).
 */
final class AtlasLoopSoakReportService
{
    public const SCHEMA_VERSION = 'atlas.loop.soak_report.v1';

    /** proxy_ratio at/above this (or proxy outright beating real) trips the faxina alarm. */
    private const PROXY_ALARM_RATIO = 0.34;

    public function __construct(
        private readonly ?AtlasLoopRealWorkScorecardService $scorecard = null,
        private readonly ?AtlasLoopCapabilityTrendService $trend = null,
        private readonly ?AtlasLoopObraCostEstimator $cost = null,
    ) {}

    /**
     * @return array<string,mixed> the canonical {@see self::SCHEMA_VERSION} payload.
     */
    public function report(int $windowHours = 1, ?string $campaignId = null): array
    {
        $hours = max(1, $windowHours);
        $campaignId = ($campaignId !== null && trim($campaignId) !== '') ? trim($campaignId) : null;
        $since = Carbon::now()->subHours($hours);

        $deliveries = $this->deliveries($since, $campaignId);
        $quality = $this->quality($campaignId);
        $compounding = $this->compounding($hours, $since, $campaignId);
        $spend = $this->spend($since, $campaignId);
        $leverImpact = $this->leverImpact($since, $campaignId);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'window_hours' => $hours,
            'campaign_id' => $campaignId,
            'deliveries' => $deliveries,
            'quality' => $quality,
            'compounding' => $compounding,
            'spend' => $spend,
            'lever_impact' => $leverImpact,
            'verdict' => $this->verdict($deliveries, $quality, $compounding, $spend),
        ];
    }

    // ───────────────────────── (a) DELIVERIES ─────────────────────────

    /**
     * What MERGED to main in the window (the governed gain surface). Count + per-work-type breakdown (the
     * task's objective_kind, the honest work-type) + diff-size stats. Fail-open to a zeroed section.
     *
     * @return array<string,mixed>
     */
    private function deliveries(Carbon $since, ?string $campaignId): array
    {
        $empty = ['merged_count' => 0, 'by_work_type' => [], 'diff_bytes_total' => 0, 'diff_bytes_avg' => 0, 'available' => false];
        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return $empty;
        }
        try {
            $rows = $this->mergedProposals($since, $campaignId);
        } catch (Throwable) {
            return $empty;
        }

        $kinds = $this->workTypesFor($rows);
        $byType = [];
        $diffTotal = 0;
        foreach ($rows as $row) {
            $kind = $kinds[$this->str($row->id)] ?? 'unknown';
            $byType[$kind] = ($byType[$kind] ?? 0) + 1;
            $diffTotal += max(0, (int) ($row->diff_bytes ?? 0));
        }
        ksort($byType);
        $count = count($rows);

        return [
            'merged_count' => $count,
            'by_work_type' => $byType,
            'diff_bytes_total' => $diffTotal,
            'diff_bytes_avg' => $count > 0 ? (int) round($diffTotal / $count) : 0,
            'available' => true,
        ];
    }

    // ───────────────────────── (b) QUALITY ─────────────────────────

    /**
     * The EXISTING honest material-vs-proxy signal ({@see AtlasLoopRealWorkScorecardService}). proxy_alarm
     * fires when proxy is at/over the alarm ratio OR outright beats real work — the faxina-drift tripwire the
     * operator watches across consecutive hourly reports.
     *
     * @return array<string,mixed>
     */
    private function quality(?string $campaignId): array
    {
        try {
            $card = ($this->scorecard ?? new AtlasLoopRealWorkScorecardService)->scorecard($campaignId);
        } catch (Throwable) {
            return ['available' => false, 'proxy_alarm' => false];
        }
        $real = (float) ($card['real_work_ratio'] ?? 0.0);
        $proxy = (float) ($card['proxy_ratio'] ?? 0.0);
        $cosmetic = (float) ($card['cosmetic_ratio'] ?? 0.0);

        return [
            'available' => ($card['status'] ?? null) === 'ok',
            'real_work_ratio' => $real,
            'proxy_ratio' => $proxy,
            'cosmetic_ratio' => $cosmetic,
            'real_work_tasks' => (int) ($card['real_work_tasks'] ?? 0),
            'proxy_refactor_tasks' => (int) ($card['proxy_refactor_tasks'] ?? 0),
            'cosmetic_tasks' => (int) ($card['cosmetic_tasks'] ?? 0),
            'deterministic_real_work_proposals' => (int) ($card['deterministic_real_work_proposals'] ?? 0),
            // ALARM: faxina is winning — proxy at/over the alarm bar, or proxy+cosmetic beating real work.
            'proxy_alarm' => ($proxy >= self::PROXY_ALARM_RATIO) || (($proxy + $cosmetic) > $real && ($proxy + $cosmetic) > 0.0),
            'scorecard_verdict' => $card['verdict'] ?? null,
        ];
    }

    // ───────────────────────── (c) COMPOUNDING (the Fibonacci proof) ─────────────────────────

    /**
     * The signal that separates EVOLUTION from activity: the CapabilityTrend slope (is the clean-delivery rate
     * bending up?) AND whether the DARED rung-size is CLIMBING (each delivered rung bigger than the last). The
     * rung proxy is the merged work's node_count (its scope; falls back to diff bytes) bucketed oldest→newest;
     * a positive least-squares slope over the non-empty buckets == the rung is growing == compounding.
     *
     * @return array<string,mixed>
     */
    private function compounding(int $hours, Carbon $since, ?string $campaignId): array
    {
        $trend = ['enabled' => false, 'slope' => 0.0, 'bending' => false, 'samples' => 0];
        try {
            // trend()'s first arg is the PER-BUCKET span; divide the window into ~7 buckets so the slope is
            // measured ACROSS the window (a too-wide span collapses every delivery into one bucket => flat).
            $t = ($this->trend ?? new AtlasLoopCapabilityTrendService)->trend(max(1, (int) ceil($hours / 7)), 7);
            $trend = ['enabled' => (bool) ($t['enabled'] ?? false), 'slope' => (float) ($t['slope'] ?? 0.0), 'bending' => (bool) ($t['bending'] ?? false), 'samples' => (int) ($t['samples'] ?? 0)];
        } catch (Throwable) {
            // degrade to the disabled trend
        }

        $rung = $this->rungSizeTrend($since, $campaignId, max(2, min(7, $hours)));

        return [
            'capability_trend' => $trend,
            'rung_size' => $rung,
            // The headline compounding claim: capability bending up AND the dared rung climbing.
            'compounding' => ($trend['bending'] === true) && ($rung['climbing'] === true),
        ];
    }

    /**
     * Average DARED rung-size (node_count, fallback diff bytes) per oldest→newest time bucket over the window,
     * plus the least-squares slope (reusing the trend's own slope math). climbing == slope > 0 over >=2
     * non-empty buckets.
     *
     * @return array<string,mixed>
     */
    private function rungSizeTrend(Carbon $since, ?string $campaignId, int $bucketCount): array
    {
        $empty = ['buckets' => [], 'slope' => 0.0, 'climbing' => false, 'samples' => 0];
        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return $empty;
        }
        try {
            $rows = $this->mergedProposals($since, $campaignId);
        } catch (Throwable) {
            return $empty;
        }
        if ($rows === []) {
            return $empty;
        }

        $nodeCounts = $this->nodeCountsFor($rows);
        $now = Carbon::now();
        $windowSpanHours = max(1, (int) abs((float) $now->diffInHours($since)));
        $bucketSpan = max(1, (int) ceil($windowSpanHours / max(2, $bucketCount)));

        $buckets = array_fill(0, max(2, $bucketCount), ['total' => 0, 'sum' => 0]);
        $last = max(2, $bucketCount) - 1;
        $samples = 0;
        foreach ($rows as $row) {
            $ageHours = (int) abs((float) $now->diffInHours(Carbon::parse((string) $row->updated_at)));
            // oldest bucket 0 .. newest bucket $last
            $idx = (int) min($last, max(0, $last - intdiv($ageHours, $bucketSpan)));
            $size = $nodeCounts[$this->str($row->id)] ?? max(1, (int) round(((int) ($row->diff_bytes ?? 0)) / 200));
            $buckets[$idx]['total']++;
            $buckets[$idx]['sum'] += max(1, (int) $size);
            $samples++;
        }

        $out = [];
        foreach ($buckets as $i => $b) {
            $avg = $b['total'] > 0 ? $b['sum'] / $b['total'] : 0.0;
            $out[] = ['index' => $i, 'total' => (int) $b['total'], 'rate' => round($avg, 3)];
        }
        $slope = AtlasLoopCapabilityTrendService::slope($out);

        return ['buckets' => $out, 'slope' => round($slope, 4), 'climbing' => $slope > 1e-9, 'samples' => $samples];
    }

    // ───────────────────────── (d) SPEND + regressions ─────────────────────────

    /**
     * Per-provider delivery count + (best-effort) mean cost from exploration telemetry, plus the regressions
     * the sentinel caught (fix-forward tasks) in the window. Cost is fail-open empty (no ledger => 0 signal).
     *
     * @return array<string,mixed>
     */
    private function spend(Carbon $since, ?string $campaignId): array
    {
        $byProvider = [];
        if (DatabaseTableAvailability::has('atlas_loop_proposals')) {
            try {
                foreach ($this->mergedProposals($since, $campaignId) as $row) {
                    $p = $this->str($row->provider) ?: 'loop_default';
                    $byProvider[$p] = ($byProvider[$p] ?? 0) + 1;
                }
            } catch (Throwable) {
                $byProvider = [];
            }
        }
        ksort($byProvider);

        return [
            'deliveries_by_provider' => $byProvider,
            'provider_mean_cost_usd' => $this->providerCost($since, $campaignId),
            'regressions_caught' => $this->regressionsCaught($since, $campaignId),
        ];
    }

    /**
     * The anti-starvation lever read: compare the first and second halves of the soak window using the
     * same funnel semantics as AtlasLoopFunnelService, but windowed directly over the runtime tables.
     * Fail-open to a degraded, zeroed block.
     *
     * @return array<string,mixed>
     */
    private function leverImpact(Carbon $since, ?string $campaignId): array
    {
        $emptyCounts = ['generated' => 0, 'admitted' => 0, 'attempted' => 0, 'certified' => 0];
        $degraded = AtlasLoopLeverImpactMeter::impact($emptyCounts, $emptyCounts) + [
            'available' => false,
            'before' => $emptyCounts,
            'after' => $emptyCounts,
        ];
        if (! DatabaseTableAvailability::has('atlas_loop_tasks') || ! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return $degraded;
        }

        try {
            $now = Carbon::now();
            $mid = $since->copy()->addSeconds((int) floor($since->diffInSeconds($now) / 2));
            $before = $this->windowedFunnelCounts($since, $mid, false, $campaignId);
            $after = $this->windowedFunnelCounts($mid, $now, true, $campaignId);

            return AtlasLoopLeverImpactMeter::impact($before, $after) + [
                'available' => true,
                'before' => $before,
                'after' => $after,
            ];
        } catch (Throwable) {
            return $degraded;
        }
    }

    /** @return array<string,float> provider => mean cost from exploration attempt telemetry (best-effort). */
    private function providerCost(Carbon $since, ?string $campaignId): array
    {
        if (! DatabaseTableAvailability::has('atlas_loop_explorations')) {
            return [];
        }
        try {
            $q = DB::table('atlas_loop_explorations')->where('updated_at', '>=', $since);
            if ($campaignId !== null) {
                $q->where('campaign_id', $campaignId);
            }
            $metrics = [];
            foreach ($q->limit(5000)->get(['attempt_metrics']) as $row) {
                $decoded = json_decode((string) ($row->attempt_metrics ?? ''), true);
                foreach (is_array($decoded) ? $decoded : [] as $attempt) {
                    if (is_array($attempt)) {
                        $metrics[] = $attempt;
                    }
                }
            }

            return ($this->cost ?? new AtlasLoopObraCostEstimator)->aggregate($metrics);
        } catch (Throwable) {
            return [];
        }
    }

    private function regressionsCaught(Carbon $since, ?string $campaignId): int
    {
        if (! DatabaseTableAvailability::has('atlas_loop_tasks')) {
            return 0;
        }
        try {
            $q = AtlasLoopTask::query()->where('source', 'regression_sentinel')->where('created_at', '>=', $since);
            if ($campaignId !== null) {
                $q->where('campaign_id', $campaignId);
            }

            return (int) $q->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Windowed counts with the same semantics as AtlasLoopFunnelService::snapshot():
     * generated=tasks discovered, admitted=proposals created, attempted=running+done tasks,
     * certified=certified proposals.
     *
     * @return array{generated:int, admitted:int, attempted:int, certified:int}
     */
    private function windowedFunnelCounts(Carbon $from, Carbon $to, bool $includeEnd, ?string $campaignId): array
    {
        $taskWindow = function (string $column) use ($from, $to, $includeEnd, $campaignId) {
            $q = DB::table('atlas_loop_tasks')->where($column, '>=', $from)
                ->where($column, $includeEnd ? '<=' : '<', $to);
            if ($campaignId !== null) {
                $q->where('campaign_id', $campaignId);
            }

            return $q;
        };
        $proposalWindow = function (string $column) use ($from, $to, $includeEnd, $campaignId) {
            $q = DB::table('atlas_loop_proposals')->where($column, '>=', $from)
                ->where($column, $includeEnd ? '<=' : '<', $to);
            if ($campaignId !== null) {
                $q->where('campaign_id', $campaignId);
            }

            return $q;
        };

        return [
            'generated' => (int) $taskWindow('created_at')->count(),
            'admitted' => (int) $proposalWindow('created_at')->count(),
            'attempted' => (int) $taskWindow('updated_at')->whereIn('status', [AtlasLoopTask::STATUS_DONE, AtlasLoopTask::STATUS_RUNNING])->count(),
            'certified' => (int) $proposalWindow('updated_at')->where('status', AtlasLoopProposal::STATUS_CERTIFIED)->count(),
        ];
    }

    // ───────────────────────── verdict ─────────────────────────

    /**
     * The one-line "is it evolving WELL?" read. EVOLVING requires real deliveries, no proxy alarm, the rung
     * climbing (or too few samples to tell yet), and no UN-repaired regression. Anything else => a named flag.
     *
     * @return array<string,mixed>
     */
    private function verdict(array $deliveries, array $quality, array $compounding, array $spend): array
    {
        $flags = [];
        if ((int) ($deliveries['merged_count'] ?? 0) === 0) {
            $flags[] = 'no_deliveries';
        }
        if (($quality['proxy_alarm'] ?? false) === true) {
            $flags[] = 'proxy_drift';
        }
        $rung = $compounding['rung_size'] ?? [];
        if ((int) ($rung['samples'] ?? 0) >= 2 && ($rung['climbing'] ?? false) !== true) {
            $flags[] = 'rung_flat';
        }
        if ((int) ($spend['regressions_caught'] ?? 0) > 0) {
            $flags[] = 'regressions_caught';
        }

        return [
            'evolving' => $flags === [] && (int) ($deliveries['merged_count'] ?? 0) > 0,
            'compounding' => ($compounding['compounding'] ?? false) === true,
            'flags' => $flags,
        ];
    }

    // ───────────────────────── shared reads ─────────────────────────

    /**
     * Merged-to-main proposals in the window (the gain surface), newest first. Returns stdClass rows with a
     * pre-computed diff_bytes so callers never re-strlen.
     *
     * @return list<object>
     */
    private function mergedProposals(Carbon $since, ?string $campaignId): array
    {
        $q = DB::table('atlas_loop_proposals')
            ->where('merged_to_main', true)
            ->where('updated_at', '>=', $since);
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return $q->orderByDesc('updated_at')->limit(5000)
            ->get(['id', 'task_id', 'provider', 'objective', 'diff_text', 'updated_at'])
            ->map(static function (object $r): object {
                $r->diff_bytes = strlen((string) ($r->diff_text ?? ''));
                unset($r->diff_text);

                return $r;
            })->all();
    }

    /**
     * The honest work-type (task.objective_kind) for each proposal id, via its source task. Proposals without
     * a resolvable task fall back to 'unknown' at the call site.
     *
     * @param  list<object>  $rows
     * @return array<string,string> proposal_id => objective_kind
     */
    private function workTypesFor(array $rows): array
    {
        $tasks = $this->tasksFor($rows);
        $out = [];
        foreach ($rows as $row) {
            $task = $tasks[$this->str($row->task_id)] ?? null;
            if ($task === null) {
                continue;
            }
            $payload = json_decode((string) ($task->payload ?? ''), true);
            $kind = is_array($payload) ? trim((string) ($payload['objective_kind'] ?? '')) : '';
            if ($kind !== '') {
                $out[$this->str($row->id)] = $kind;
            }
        }

        return $out;
    }

    /**
     * node_count (the dared scope) for each proposal id, via its source task payload.
     *
     * @param  list<object>  $rows
     * @return array<string,int> proposal_id => node_count
     */
    private function nodeCountsFor(array $rows): array
    {
        $tasks = $this->tasksFor($rows);
        $out = [];
        foreach ($rows as $row) {
            $task = $tasks[$this->str($row->task_id)] ?? null;
            if ($task === null) {
                continue;
            }
            $payload = json_decode((string) ($task->payload ?? ''), true);
            if (! is_array($payload)) {
                continue;
            }
            $nc = $payload['node_count'] ?? (is_array($payload['allowed_files'] ?? null) ? count($payload['allowed_files']) : null);
            if ($nc !== null && (int) $nc > 0) {
                $out[$this->str($row->id)] = (int) $nc;
            }
        }

        return $out;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string,object> task_id => task row (id, payload)
     */
    private function tasksFor(array $rows): array
    {
        if (! DatabaseTableAvailability::has('atlas_loop_tasks')) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $tid = $this->str($row->task_id);
            if ($tid !== '') {
                $ids[$tid] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $out = [];
            foreach (DB::table('atlas_loop_tasks')->whereIn('id', array_keys($ids))->limit(5000)->get(['id', 'payload']) as $t) {
                $out[$this->str($t->id)] = $t;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}

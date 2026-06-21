<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loop EVOLUTION report — measures whether a campaign actually made the loop MORE powerful, not just busy.
 *
 * Wraps the honest {@see AtlasLoopRealWorkScorecardService} (real_work vs proxy vs cosmetic vs unknown) and
 * adds the four dimensions that scorecard lacks for judging EVOLUTION rather than activity:
 *   1. origination_source — which supply lane minted each task (rédea / coverage / sibling / bug-harvest /
 *      decomposition / research). This is THE lens for "is the loop originating plural work or one source?".
 *   2. tasks_per_hour     — throughput (is it sustaining work or stalling after a batch?).
 *   3. cert_rate          — certified proposals / tasks (is the supply CONVERTING to proven deliveries?).
 *   4. gain               — before/after delta vs an optional baseline campaign (did a change make it better?).
 *
 * Read-only by construction: it only SELECTs, never mutates loop state, so it is always safe to run against a
 * live campaign. Fail-soft: a missing scorecard / table degrades a field to a zero, never throws.
 */
final class AtlasLoopEvolutionReportCommand extends Command
{
    protected $signature = 'atlas:loop:evolution-report
        {--campaign-id= : Campaign to measure (default: most recent campaign)}
        {--baseline-campaign-id= : Optional earlier campaign to compute the GAIN delta against}
        {--json : Emit machine JSON only}';

    protected $description = 'Measure a loop campaign\'s real evolution: substantive supply, origination source, throughput, cert rate, and gain vs a baseline.';

    public function handle(AtlasLoopRealWorkScorecardService $scorecards): int
    {
        $cid = (string) ($this->option('campaign-id') ?: $this->latestCampaignId());
        if ($cid === '') {
            $this->emit(['schema_version' => 'atlas.loop.evolution_report.v1', 'status' => 'no_campaign'], true);

            return self::FAILURE;
        }

        $report = [
            'schema_version' => 'atlas.loop.evolution_report.v1',
            'status' => 'ok',
            'generated_at' => now()->toIso8601String(),
        ] + $this->measure($cid, $scorecards);

        $baselineId = trim((string) ($this->option('baseline-campaign-id') ?? ''));
        if ($baselineId !== '' && $baselineId !== $cid) {
            $baseline = $this->measure($baselineId, $scorecards);
            $report['baseline'] = $baseline;
            $report['gain'] = $this->gain($baseline, $report);
        }

        $this->emit($report, (bool) $this->option('json'));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function measure(string $cid, AtlasLoopRealWorkScorecardService $scorecards): array
    {
        $sc = [];
        try {
            $sc = $scorecards->scorecard($cid);
        } catch (Throwable) {
            $sc = [];
        }

        $tasks = AtlasLoopTask::query()->where('campaign_id', $cid)->get(['source', 'status', 'created_at']);
        $total = $tasks->count();

        $bySource = [];
        $byStatus = [];
        foreach ($tasks as $t) {
            $src = $this->normalizeSource((string) ($t->source ?? ''));
            $bySource[$src] = ($bySource[$src] ?? 0) + 1;
            $st = (string) $t->status;
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        }
        arsort($bySource);

        $cert = $this->safeCount('atlas_loop_proposals', $cid);
        $failed = (int) ($byStatus['failed'] ?? 0);

        $start = optional(AtlasLoopCampaign::query()->find($cid))->created_at;
        $hours = $start ? max(0.01, (float) $start->diffInSeconds(now()) / 3600.0) : 0.0;

        // real_work in the scorecard INCLUDES verification (coverage IS real verification work, not proxy);
        // the SUBSTANTIVE axis for evolution is real work that is NOT mere coverage.
        $coverage = (int) ($sc['verification_tasks'] ?? 0);
        $substantive = max(0, (int) ($sc['real_work_tasks'] ?? 0) - $coverage);
        $proxy = (int) ($sc['proxy_refactor_tasks'] ?? 0);
        $cosmetic = (int) ($sc['cosmetic_tasks'] ?? 0);
        $unknown = (int) ($sc['unknown_tasks'] ?? 0);

        return [
            'campaign_id' => $cid,
            'duration_hours' => round($hours, 2),
            'tasks_total' => $total,
            'tasks_per_hour' => $hours > 0.0 ? round($total / $hours, 2) : 0.0,
            'substantive' => $substantive,
            'coverage' => $coverage,
            'proxy' => $proxy,
            'cosmetic' => $cosmetic,
            'unknown' => $unknown,
            'substantive_ratio' => $total > 0 ? round($substantive / $total, 3) : 0.0,
            'cert_count' => $cert,
            'cert_rate' => $total > 0 ? round($cert / $total, 3) : 0.0,
            'failed' => $failed,
            'origination_source' => $bySource,
            'status_breakdown' => $byStatus,
            'verdict' => $sc['verdict'] ?? null,
            'claim_policy' => $sc['claim_policy'] ?? null,
        ];
    }

    /**
     * Positive delta = the measured campaign is BETTER than the baseline on that axis. The headline axes are
     * the ones that mean "the loop got more powerful": more substantive supply, higher throughput, higher
     * cert conversion, lower proxy. Cosmetic deltas are intentionally excluded — they are never a goal.
     *
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $cur
     * @return array<string,mixed>
     */
    private function gain(array $base, array $cur): array
    {
        $d = static fn (string $k): float => round((float) ($cur[$k] ?? 0) - (float) ($base[$k] ?? 0), 3);

        return [
            'substantive' => $d('substantive'),
            'substantive_ratio' => $d('substantive_ratio'),
            'tasks_per_hour' => $d('tasks_per_hour'),
            'cert_count' => $d('cert_count'),
            'cert_rate' => $d('cert_rate'),
            'proxy' => $d('proxy'),
            // time-fair: campaigns run different durations, so the BETTER verdict judges RATES (per-hour /
            // ratios), never raw counts — a longer baseline must not look "better" just by having run longer.
            'better' => $d('substantive_ratio') >= 0 && $d('tasks_per_hour') >= 0 && $d('cert_rate') >= 0,
        ];
    }

    private function normalizeSource(string $source): string
    {
        $source = trim(mb_strtolower($source));
        if ($source === '') {
            return 'unattributed';
        }
        // collapse 'producer:objective' / 'coverage:deficit' etc. to the supplying-lane prefix.
        $prefix = explode(':', $source, 2)[0];

        return $prefix !== '' ? $prefix : $source;
    }

    private function safeCount(string $table, string $cid): int
    {
        try {
            return (int) DB::table($table)->where('campaign_id', $cid)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function latestCampaignId(): string
    {
        try {
            return (string) (AtlasLoopCampaign::query()->latest('created_at')->value('id') ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function emit(array $report, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('Loop evolution — '.($report['campaign_id'] ?? '?'));
        if (($report['status'] ?? '') !== 'ok') {
            $this->warn('status: '.($report['status'] ?? 'unknown'));

            return;
        }
        $this->line(sprintf(
            'tasks=%d (%.2f/h, %.1fh)  SUBSTANTIVE=%d (%.0f%%)  coverage=%d  proxy=%d  cosmetic=%d  unknown=%d',
            $report['tasks_total'], $report['tasks_per_hour'], $report['duration_hours'],
            $report['substantive'], 100 * (float) $report['substantive_ratio'],
            $report['coverage'], $report['proxy'], $report['cosmetic'], $report['unknown'],
        ));
        $this->line(sprintf('cert=%d (%.0f%%)  failed=%d', $report['cert_count'], 100 * (float) $report['cert_rate'], $report['failed']));
        $src = [];
        foreach ((array) $report['origination_source'] as $k => $v) {
            $src[] = $k.'='.$v;
        }
        $this->line('origination: '.implode('  ', $src));
        if (isset($report['gain'])) {
            $g = $report['gain'];
            $this->line(sprintf(
                'GAIN vs baseline: substantive %+d (%+.0f%%)  tasks/h %+.2f  cert %+d  proxy %+d  => %s',
                $g['substantive'], 100 * (float) $g['substantive_ratio'], $g['tasks_per_hour'],
                $g['cert_count'], $g['proxy'], $g['better'] ? 'BETTER ✓' : 'mixed/worse',
            ));
        }
    }
}

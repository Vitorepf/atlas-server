<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSoakReportService;
use Illuminate\Console\Command;

/**
 * THE SOAK INSTRUMENT (provider-free, read-only) — how the operator answers "is the loop evolving WELL?"
 * during a supervised soak. For a rolling window (default 1h) it prints: DELIVERIES (what merged, by
 * work-type, size), QUALITY (the honest material-vs-proxy ratios — proxy_alarm = faxina drift), COMPOUNDING
 * (CapabilityTrend slope + whether the dared RUNG-SIZE is climbing — the Fibonacci proof), and SPEND
 * (per-provider + regressions the sentinel caught). Never invokes a provider, never runs a cycle.
 */
class AtlasLoopSoakReportCommand extends Command
{
    protected $signature = 'atlas:loop:soak-report
        {--hours=1 : Rolling window in hours (default 1h)}
        {--campaign-id= : Restrict to one campaign (default: all)}
        {--json : Canonical JSON output}';

    protected $description = 'Soak report (provider-free): deliveries + quality (material vs proxy) + compounding (is the rung climbing?) + spend/regressions.';

    public function handle(AtlasLoopSoakReportService $service): int
    {
        $report = $service->report((int) $this->option('hours'), trim((string) $this->option('campaign-id')) ?: null);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $d = $report['deliveries'];
        $q = $report['quality'];
        $c = $report['compounding'];
        $s = $report['spend'];
        $v = $report['verdict'];

        $this->components->info(sprintf('Soak report — last %dh', (int) $report['window_hours']));
        $this->components->twoColumnDetail('<options=bold>Deliveries</> merged to main', (string) $d['merged_count']);
        foreach ((array) $d['by_work_type'] as $kind => $n) {
            $this->components->twoColumnDetail('  · '.$kind, (string) $n);
        }
        $this->components->twoColumnDetail('  diff bytes (avg)', (string) $d['diff_bytes_avg']);

        $this->components->twoColumnDetail('<options=bold>Quality</> real / proxy / cosmetic', sprintf('%.0f%% / %.0f%% / %.0f%%', 100 * (float) ($q['real_work_ratio'] ?? 0), 100 * (float) ($q['proxy_ratio'] ?? 0), 100 * (float) ($q['cosmetic_ratio'] ?? 0)));
        $this->components->twoColumnDetail('  proxy alarm (faxina drift)', ($q['proxy_alarm'] ?? false) ? '<fg=red;options=bold>YES — investigate</>' : 'no');

        $trend = $c['capability_trend'];
        $rung = $c['rung_size'];
        $this->components->twoColumnDetail('<options=bold>Compounding</> capability slope', sprintf('%+.4f (%s)', (float) $trend['slope'], $trend['bending'] ? 'bending up' : 'flat'));
        $this->components->twoColumnDetail('  dared rung-size climbing?', ($rung['climbing'] ?? false) ? '<fg=green;options=bold>YES (slope '.sprintf('%+.3f', (float) $rung['slope']).')</>' : 'not yet ('.(int) $rung['samples'].' samples)');
        $this->components->twoColumnDetail('  FIBONACCI (bend ∧ rung↑)', ($c['compounding'] ?? false) ? '<fg=green;options=bold>YES</>' : 'no');

        foreach ((array) $s['deliveries_by_provider'] as $p => $n) {
            $this->components->twoColumnDetail('<options=bold>Spend</> '.$p, $n.' delivered');
        }
        $this->components->twoColumnDetail('  regressions caught', (string) $s['regressions_caught']);

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>VERDICT</>', ($v['evolving'] ?? false) ? '<fg=green;options=bold>EVOLVING</>' : '<fg=yellow>'.(($v['flags'] ?? []) ? implode(', ', $v['flags']) : 'idle').'</>');

        return self::SUCCESS;
    }
}

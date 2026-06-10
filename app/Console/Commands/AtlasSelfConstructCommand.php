<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementMetaMetricService;
use Illuminate\Console\Command;

/**
 * Self-construction loop — "Atlas builds Atlas". Detects improvement signals in the
 * Atlas codebase (or takes operator-supplied --request gaps) and runs each through
 * the BRAIN-ANCHORED Mission e2e pipe + the OUT-OF-PROCESS relevance gate, producing
 * BRANCHES for the operator to review + merge. NEVER merges, NEVER touches main.
 * Bounded by --max.
 *
 * S3.F3 surfaces:
 *   --status [--json]  the HONEST meta-metric: live per-cycle history (signals,
 *     generated, relevance passed/rejected, branches, brain growth) + the
 *     relevance-pass-rate trend. No claim of acceleration — just the measured trend.
 *   --watch            the AUTONOMOUS continuous mode. SAFE because the relevance gate
 *     rejects noise every cycle — but the autonomous loop is the highest-stakes
 *     capability, so it requires an EXPLICIT config opt-in
 *     (atlas.self_construction.autonomous_enabled, default OFF) IN ADDITION to this
 *     flag + the kill-switch file, so it can NEVER run by accident.
 *
 * S3.F4 — GOVERNANCE HARDENING (safe to leave running). Each run is now ADVERSARIALLY
 * re-checked (a gate-passed branch the independent re-check refuses is HELD as
 * needs_review, not surfaced as vetted), BOUNDED by a per-run branch cap, KILLABLE
 * mid-run (the stop file is honored before each signal), and every decision writes an
 * honest audit RECEIPT (no silent action).
 *
 * NOTE: each real delivery makes a provider call (operator spend) — run with intent.
 */
class AtlasSelfConstructCommand extends Command
{
    protected $signature = 'atlas:self-construct
        {--max=1 : maximum improvement signals to act on this run}
        {--request=* : explicit operator improvement request(s), merged ahead of code markers}
        {--repo= : repo dir to scan + materialize into (default: app base path)}
        {--provider=codex_cli : provider for the generation step}
        {--watch : autonomous continuous mode (one bounded cycle per --interval) until the kill-switch; requires the autonomous_enabled opt-in}
        {--interval=3600 : seconds between cycles in --watch mode (min 60)}
        {--status : print the HONEST per-cycle meta-metric (history + trend + brain growth) and exit}
        {--json : machine-readable output}';

    protected $description = 'Self-construction: Atlas detects + delivers its own improvements as branches (you merge).';

    public function handle(
        AtlasSelfConstructionLoopService $loop,
        AtlasSelfImprovementMetaMetricService $meta,
    ): int {
        // --status is a pure read of the persisted history — no cycle, no spend.
        if ((bool) $this->option('status')) {
            return $this->printStatus($meta);
        }

        if (! (bool) $this->option('watch')) {
            $this->runCycle($loop);

            return self::SUCCESS;
        }

        return $this->runWatch($loop);
    }

    /**
     * --watch: autonomous continuous mode. DEFAULT-OFF: it refuses to start unless the
     * operator has explicitly enabled the autonomous capability in config — the --watch
     * flag ALONE is not enough (a typo / a stale cron must not silently run an
     * unattended self-modifying loop). Once running it is bounded (ONE cycle per
     * interval) and killable at any moment via the stop file.
     */
    private function runWatch(AtlasSelfConstructionLoopService $loop): int
    {
        if (! (bool) config('atlas.self_construction.autonomous_enabled', false)) {
            $this->error('Autonomous --watch mode is OFF by default (highest-stakes capability).');
            $this->line('Enable it explicitly to allow the unattended self-modifying loop:');
            $this->line('  ATLAS_SELF_CONSTRUCTION_AUTONOMOUS_ENABLED=true');
            $this->line('A single (non-watch) run works without this — only the autonomous REPETITION is gated.');

            return self::FAILURE;
        }

        $interval = max(60, (int) $this->option('interval'));
        $killFile = storage_path('app/atlas-self-construct.stop');
        // FAIL-SAFE: do NOT clear a pre-existing stop file at startup. If the operator
        // (or a prior abort) left the kill-switch tripped, the autonomous loop must
        // honor it AT CYCLE 0 — never run an unattended self-modifying cycle when a stop
        // is already in place. The kill-switch is thus honored BEFORE the first cycle,
        // not only between cycles (true "kill at any moment", including before start).
        $this->info('watch mode (autonomous, opt-in ON) — one cycle every '.$interval.'s. KILL ANYTIME:  touch '.$killFile);
        $cycle = 0;
        while (true) {
            if (is_file($killFile)) {
                $this->info('kill-switch tripped — stopping after '.$cycle.' cycle(s).');
                @unlink($killFile);
                break;
            }
            $this->line('— cycle '.(++$cycle).' ('.now()->toTimeString().') —');
            $this->runCycle($loop);
            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function printStatus(AtlasSelfImprovementMetaMetricService $meta): int
    {
        $status = $meta->status();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Self-construction meta-metric — HONEST per-cycle history (measured; no acceleration claimed).');
        $totals = (array) ($status['totals'] ?? []);
        $this->line(sprintf(
            '  cycles: %d  |  detected: %d  |  generated: %d  |  passed: %d  |  rejected: %d  |  branches: %d',
            (int) ($status['cycle_count'] ?? 0),
            (int) ($totals['signals_detected'] ?? 0),
            (int) ($totals['generated'] ?? 0),
            (int) ($totals['relevance_passed'] ?? 0),
            (int) ($totals['relevance_rejected'] ?? 0),
            (int) ($totals['branches_delivered'] ?? 0),
        ));
        $rate = $totals['relevance_pass_rate'] ?? null;
        $trend = (array) ($status['relevance_pass_rate_trend'] ?? []);
        $this->line(sprintf(
            '  lifetime on-target rate: %s  |  trend: %s (first %s → last %s, Δ %s)',
            $rate === null ? 'n/a' : (string) $rate,
            (string) ($trend['direction'] ?? 'n/a'),
            $this->fmt($trend['first'] ?? null),
            $this->fmt($trend['last'] ?? null),
            $this->fmt($trend['delta'] ?? null),
        ));
        $growth = (array) ($status['brain_growth'] ?? []);
        $this->line(sprintf(
            '  brain growth: +%d refs over the window (size %s → %s)',
            (int) ($growth['nodes_added_total'] ?? 0),
            $this->fmt($growth['first_total'] ?? null),
            $this->fmt($growth['last_total'] ?? null),
        ));
        $this->line('');
        $this->line('  '.(string) ($status['note'] ?? ''));

        return self::SUCCESS;
    }

    private function fmt(mixed $v): string
    {
        return $v === null ? 'n/a' : (string) $v;
    }

    private function runCycle(AtlasSelfConstructionLoopService $loop): void
    {
        $result = $loop->run([
            'max' => (int) $this->option('max'),
            'requests' => (array) $this->option('request'),
            'repo_dir' => $this->stringOption('repo') ?: base_path(),
            'delivery' => array_filter(['provider' => $this->stringOption('provider')], static fn ($v): bool => $v !== null && $v !== ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info('Self-construction — Atlas improves Atlas (brain-anchored; relevance-gated; adversarially re-checked; branches only, you merge).');
        $precision = $result['relevance_precision'] ?? null;
        $this->line(sprintf(
            '  detected: %d  |  delivered: %d  |  accepted: %d  |  needs review: %d  |  rejected: %d  |  on-target rate: %s',
            (int) ($result['detected'] ?? 0),
            (int) ($result['delivered_count'] ?? 0),
            (int) ($result['accepted_count'] ?? 0),
            (int) ($result['needs_review_count'] ?? 0),
            (int) ($result['rejected_count'] ?? 0),
            $precision === null ? 'n/a' : (string) $precision,
        ));
        // S3.F4 bounds: surface a reached branch cap or a mid-run kill so the operator
        // knows the run halted deliberately (not silently), never hiding a skipped tail.
        if (! empty($result['branch_cap_reached'])) {
            $this->line(sprintf('  ⚑ per-run branch cap reached (%d) — remaining signals skipped this run.', (int) ($result['branch_cap'] ?? 0)));
        }
        if (! empty($result['killed_mid_run'])) {
            $this->line('  ⚑ kill-switch tripped mid-run — remaining signals skipped, processed outcomes kept.');
        }
        foreach ((array) ($result['outcomes'] ?? []) as $o) {
            $sig = $o['signal']['signal'] ?? '?';
            if ($o['accepted'] ?? false) {
                $this->line('  ✓ '.($o['branch'] ?? '?').'  ←  '.$sig);
            } elseif ($o['needs_review'] ?? false) {
                $reason = $o['recheck']['reason'] ?? '?';
                $this->line('  ? NEEDS REVIEW ('.$reason.') — branch HELD for you: '.($o['held_branch'] ?? '?').'  ←  '.$sig);
            } elseif ($o['delivered'] ?? false) {
                $this->line('  ⊘ REJECTED off-target ('.($o['relevance_reason'] ?? '?').') — branch discarded  ←  '.$sig);
            } else {
                $this->line('  ✗ ('.($o['stage'] ?? '?').'/'.($o['reason'] ?? '?').')  ←  '.$sig);
            }
        }
        $this->line('');
        $this->line('  '.($result['operator_action'] ?? 'review the branches'));
    }

    private function stringOption(string $key): ?string
    {
        $v = $this->option($key);

        return is_string($v) ? $v : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Console\Command;

/**
 * Atlas Evolution Loop — the 24h autonomous CAMPAIGN entry.
 *
 * Drives {@see AtlasLoopCampaignSupervisor}: discover -> generate -> grind -> persist
 * -> loop-back, for a wall-clock budget, refilling its own queue, propose-only. SHADOW
 * is the DEFAULT (full real discovery + grind + persisted proposals, stamped shadow so
 * a 24h campaign can be dry-run against real code before it is trusted); --no-shadow
 * goes active. It NEVER merges. Resume a crashed run by passing its --campaign-id.
 *
 * Run a real 24h campaign with: php artisan atlas:loop:campaign --max-seconds=86400
 */
final class AtlasLoopCampaignCommand extends Command
{
    protected $signature = 'atlas:loop:campaign
        {--campaign-id= : Resume an existing campaign by id (else a new one is opened)}
        {--goal= : Natural-language goal for a new campaign}
        {--base-workspace= : Repo root discovery scans / generator reads (default: the app root)}
        {--max-seconds= : Wall-clock budget, clamped 1..86400 (default: config, 24h)}
        {--max-proposals= : Stop after this many certified proposals (0/unset = unbounded)}
        {--max-tasks= : Stop after this many tasks processed (0/unset = unbounded)}
        {--max-usd-cents= : Provider spend ceiling in cents (0/unset = no cost cap)}
        {--scenarios= : Candidate scenarios explored per task (default: loop config)}
        {--workers= : Parallel grind workers (default: atlas.loop.campaign.workers; only active when atlas.loop.parallel.enabled)}
        {--provider= : Pin a provider key (default: empty = loop default / Atlas Decide)}
        {--sleep-seconds= : Rate-limit: seconds between cycles}
        {--idle-on-starvation : Keep a long soak alive by idling and re-scanning when supply is temporarily empty}
        {--starvation-idle-seconds= : Seconds to sleep between starved re-scans when --idle-on-starvation is enabled}
        {--no-shadow : Run active instead of the shadow default}
        {--json : Print the canonical JSON result}';

    protected $description = 'Run the Atlas Evolution Loop 24h autonomous campaign: self-feeding, propose-only, crash-safe (never merges).';

    public function handle(AtlasLoopCampaignSupervisor $supervisor): int
    {
        // §0 MASTER SWITCH — fail-closed global gate. OFF ⇒ refuse to launch any campaign (no supervisor, no
        // grind workers). The operator runs `atlas:loop:on` first; until then the loop cannot start, even by a
        // manual or scheduled invocation. Clean no-op exit (SUCCESS, not error → no scheduler/watchdog retry-storm).
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line((string) json_encode(['schema_version' => 'atlas.loop.campaign.v1', 'master' => 'off', 'launched' => false]));

            return self::SUCCESS;
        }

        $input = array_filter([
            'campaign_id' => trim((string) ($this->option('campaign-id') ?: '')),
            'goal' => trim((string) ($this->option('goal') ?: '')),
            'base_workspace' => trim((string) ($this->option('base-workspace') ?: '')),
            'max_proposals' => $this->intOption('max-proposals'),
            'max_tasks' => $this->intOption('max-tasks'),
            'max_usd_cents' => $this->intOption('max-usd-cents'),
            'scenarios' => $this->intOption('scenarios'),
            'workers' => $this->intOption('workers'),
            'provider' => trim((string) ($this->option('provider') ?: '')),
            'sleep_seconds' => $this->intOption('sleep-seconds'),
            'starvation_idle_seconds' => $this->intOption('starvation-idle-seconds'),
            'shadow' => ! (bool) $this->option('no-shadow'),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
        if ((bool) $this->option('idle-on-starvation')) {
            $input['idle_on_starvation'] = true;
        }

        // Wall-clock budget is a HARD ceiling, clamped to a real day.
        $maxSeconds = $this->intOption('max-seconds');
        if ($maxSeconds !== null) {
            $input['max_seconds'] = max(1, min(86400, $maxSeconds));
        }

        // CYCLE CONTRACT (operator mandate 2026-06-17) — NEVER start a cycle on a base too far behind main
        // (the 579-commits-behind incident: a stale base merges ancient code + wastes every token). Flag-gated
        // default-OFF (byte-identical); when armed, refuse to launch on a stale base. base=main (the soak
        // default) is 0 behind => always passes; only a stale worktree base is blocked.
        if ((bool) config('atlas.loop.base_staleness_guard_enabled', false)) {
            $base = ((string) ($input['base_workspace'] ?? '')) ?: base_path();
            $mainRef = (string) config('atlas.loop.base_staleness_main_ref', 'main');
            $max = max(0, (int) config('atlas.loop.base_staleness_max_commits_behind', 50));
            $behind = (new AtlasLoopCycleGitContract)->commitsBehindMain($base, 'HEAD', $mainRef);
            if ($behind !== null && $behind > $max) {
                $this->error('Recusado: base_workspace esta '.$behind.' commits atras de '.$mainRef.' (limite '.$max.'). Re-sincronize antes de rodar — nunca comecar um ciclo numa base velha (incidente 579-atras).');

                return self::FAILURE;
            }
            if ($behind !== null) {
                $this->info('Cycle-contract preflight OK: base '.$behind.' commits atras de '.$mainRef.' (limite '.$max.').');
            }
        }

        $this->info('Atlas Evolution Loop campaign starting ('.($input['shadow'] ? 'shadow' : 'active').', propose-only, never merges)…');
        $result = $supervisor->run($input);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Campaign</>', (string) $result['campaign_id']);
            $this->components->twoColumnDetail('Stop reason', (string) $result['stop_reason']);
            $this->components->twoColumnDetail('Cycles', (string) $result['cycles']);
            $this->components->twoColumnDetail('Tasks processed', (string) ($result['tasks_processed'] ?? 0));
            $this->components->twoColumnDetail('Proposals (certified-for-review)', (string) ($result['proposals_total'] ?? 0));
            $this->components->twoColumnDetail('Merged to main (must be no)', $result['merged_to_main'] ? 'YES — INVARIANT BROKEN' : 'no');
            $this->line('');
            $this->line('  Review proposals:  php artisan atlas:loop:campaign:status --campaign-id='.$result['campaign_id']);
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }
}

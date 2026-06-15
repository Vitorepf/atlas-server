<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopPipelineDrift;
use App\Support\AtlasPhpBinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * 24h de auto-evolução exige sobreviver à morte do supervisor (aconteceu HOJE: o soak
 * morreu silenciosamente com 2h de budget sobrando e ninguém percebeu por 20+ min).
 * Este keepalive roda em cadência: para cada campanha `running` com budget restante cujo
 * HEARTBEAT está velho E sem processo vivo, relança o supervisor detached (resume pelo
 * campaign-id — o supervisor reclama as tasks in-flight; nada se perde).
 *
 * Fail-safe: nunca mata nada; só relança quando há evidência dupla (heartbeat velho +
 * processo ausente). Gated por flag para o operador desligar.
 */
class AtlasLoopKeepaliveCommand extends Command
{
    protected $signature = 'atlas:loop:keepalive
        {--stale-minutes=10 : Heartbeat mais velho que isto (min) = suspeito}
        {--workers= : Workers do supervisor relançado (default: atlas.loop.campaign.workers)}
        {--scenarios=3 : Cenários por task no relançamento}
        {--json : Saída JSON canônica}';

    protected $description = 'Respawn automático do supervisor do Loop: campanha running com heartbeat velho e sem processo vivo é relançada (resume, nunca perde estado).';

    public function handle(): int
    {
        $out = ['schema_version' => 'atlas.loop.keepalive.v1', 'checked' => 0, 'respawned' => [], 'reaped' => [], 'healthy' => []];
        $staleMinutes = max(2, (int) $this->option('stale-minutes'));

        $campaigns = DB::table('atlas_loop_campaigns')
            ->where('status', 'running')
            ->where('kill_switch', false)
            ->where(function ($q): void {
                // Bounded campaign with budget remaining, OR an UNBOUNDED soak (max_seconds<=0 =
                // "no wall-clock cap", per the campaigns migration + the model's budgetReached()).
                // The unbounded case was WRONGLY excluded by `elapsed_seconds < max_seconds`
                // (100 < 0 = false), so a dead UNBOUNDED soak was NEVER death-respawned — exactly
                // the 24/7-independence gap. Treat <=0 as always-budget-remaining (eligible);
                // ancient abandoned rows are then separated from real soaks by the reaper below.
                $q->whereColumn('elapsed_seconds', '<', 'max_seconds')
                    ->orWhere('max_seconds', '<=', 0);
            })
            ->get();

        foreach ($campaigns as $campaign) {
            $out['checked']++;
            $id = (string) $campaign->id;

            $heartbeat = $campaign->heartbeat_at ? strtotime((string) $campaign->heartbeat_at) : 0;
            $stale = $heartbeat < (time() - $staleMinutes * 60);
            $alive = $this->supervisorAlive($id);

            // Out-of-process CODE-DRIFT recycle (belt-and-suspenders for the in-process
            // restart_on_code_drift, which only fires at the top of the supervisor loop → starved
            // during a long grind, and goes dark entirely if the boot-time git HEAD read returned
            // null). Observed live 2026-06-15: a pipeline fix sat un-loaded for 3h. Here the
            // watchdog — which runs every cadence regardless of supervisor state — recycles an
            // ALIVE supervisor whose PROCESS START predates the newest engine commit. Gated by the
            // SAME flag so the operator's one churn-vs-autonomy choice governs both checks; only
            // ENGINE drift counts (target merges never match), and the decision self-clears (the
            // respawn's start is after the commit). Lossless: respawn resumes by campaign-id.
            if ($alive && (bool) config('atlas.loop.campaign.restart_on_code_drift', true)) {
                $bootEpoch = $this->supervisorStartedAt($id);
                $driftWorkspace = (string) ($campaign->base_workspace ?: base_path());
                $latestPipelineCommit = AtlasLoopPipelineDrift::latestPipelineCommitEpoch($driftWorkspace);
                $grace = max(60, (int) config('atlas.loop.keepalive_code_drift_grace_seconds', 120));
                $aliveSeconds = $bootEpoch !== null ? max(0, time() - $bootEpoch) : 0;
                if (AtlasLoopPipelineDrift::shouldRecycle($bootEpoch, $latestPipelineCommit, $aliveSeconds, $grace)) {
                    // IN-FLIGHT GUARD: a code-drift recycle picks up newer engine code but is NOT
                    // urgent — killing a supervisor mid-grind discards MINUTES of in-flight provider
                    // work (a long iterate-to-green extract-class fires many codex/MiniMax calls, and a
                    // killed grind never certifies). DEFER the recycle while any grind is RUNNING; it
                    // fires on the next cadence once the campaign is between grinds. A genuinely STUCK
                    // grind can't defer forever — the alive+frozen-kill below (heartbeat stale beyond a
                    // generation) still recycles it. Observed live: rapid dev commits recycled the soak
                    // mid-grind every cadence, so no heavy refactor ever finished.
                    $inFlight = DB::table('atlas_loop_tasks')->where('campaign_id', $id)->where('status', 'running')->count();
                    if ($inFlight > 0) {
                        $out['drift_deferred'][] = ['campaign_id' => $id, 'running' => (int) $inFlight, 'reason' => 'code_drift_recycle_deferred_in_flight_grinds'];
                    } else {
                        $this->killSupervisor($id);
                        $this->respawn($id);
                        $out['respawned'][] = [
                            'campaign_id' => $id,
                            'reason' => 'code_drift_recycled',
                            'boot_epoch' => $bootEpoch,
                            'latest_pipeline_commit_epoch' => $latestPipelineCommit,
                            'stale_seconds' => $latestPipelineCommit !== null && $bootEpoch !== null ? $latestPipelineCommit - $bootEpoch : null,
                        ];

                        continue;
                    }
                }
            }

            // Alive-but-FROZEN self-heal: a supervisor whose heartbeat is stale FAR beyond a
            // normal generation is STUCK (post-restart-idle / deadlock), not working — the
            // refiller touches the heartbeat per target (~minutes), so a heartbeat older than
            // frozenMinutes WITH the process alive means genuinely frozen. Kill the stuck
            // process + respawn. (Plain stale+dead is the normal respawn below; stale+alive was
            // previously treated as "healthy" forever — the gap that let a frozen soak hang for
            // hours unattended, defeating the 24/7 goal.)
            $frozenMinutes = max($staleMinutes + 5, (int) config('atlas.loop.keepalive_frozen_kill_minutes', 15));
            if ($alive && $heartbeat > 0 && $heartbeat < (time() - $frozenMinutes * 60)) {
                $this->killSupervisor($id);
                $this->respawn($id);
                $out['respawned'][] = ['campaign_id' => $id, 'reason' => 'frozen_alive_killed_and_respawned', 'heartbeat_age_minutes' => (int) floor((time() - $heartbeat) / 60)];

                continue;
            }

            // REAP de órfãos: uma linha `running` SEM processo vivo cujo heartbeat é mais velho
            // que a janela de reap é uma campanha ABANDONADA (ex.: campanha-teste antiga que
            // nunca completou limpo, ou um soak unbounded morto há muito tempo) — NÃO é um soak
            // que acabou de morrer para ressuscitar. Marca completed para parar de se passar por
            // `running` e poluir o monitoramento. A janela de recência é o que separa "relança o
            // soak que morreu há 5 min" de "aposenta o zumbi de ontem". Sem isto, incluir
            // max_seconds<=0 no filtro acima ressuscitaria zumbis-teste antigos a cada 5 min.
            $reapAfterMinutes = max(60, (int) config('atlas.loop.keepalive_reap_after_minutes', 1440));
            $reapCutoff = time() - $reapAfterMinutes * 60;
            // A dead row is an abandoned orphan when EITHER its heartbeat is older than the reap
            // window, OR it NEVER beat (heartbeat<=0 — killed before its first generation, or a
            // wiped heartbeat) AND the ROW itself is older than the window. The heartbeat<=0 branch
            // closes the respawn-forever edge: without it a NULL-heartbeat dead row (now eligible
            // via the max_seconds<=0 OR filter) is never reaped (the reaper needed heartbeat>0) yet
            // always respawned (stale=true) — an infinite respawn loop. A FRESH dead row (age <
            // window) is still respawned once below; only genuinely OLD never-beat rows are reaped.
            $rowAge = $campaign->updated_at ? strtotime((string) $campaign->updated_at)
                : ($campaign->created_at ? strtotime((string) $campaign->created_at) : 0);
            $reapable = ! $alive && (
                ($heartbeat > 0 && $heartbeat < $reapCutoff)
                || ($heartbeat <= 0 && $rowAge > 0 && $rowAge < $reapCutoff)
            );
            if ($reapable) {
                DB::table('atlas_loop_campaigns')->where('id', $id)->update([
                    'status' => 'completed',
                    'stop_reason' => 'reaped_orphan_no_process',
                    'updated_at' => now(),
                ]);
                $out['reaped'][] = ['campaign_id' => $id, 'heartbeat_age_minutes' => $heartbeat > 0 ? (int) floor((time() - $heartbeat) / 60) : null];

                continue;
            }

            if (! $stale || $alive) {
                $out['healthy'][] = ['campaign_id' => $id, 'stale' => $stale, 'process_alive' => $alive];

                continue;
            }

            // Evidência dupla (heartbeat velho + processo ausente) → relança detached.
            $this->respawn($id);
            $out['respawned'][] = ['campaign_id' => $id, 'heartbeat_age_minutes' => $heartbeat > 0 ? (int) floor((time() - $heartbeat) / 60) : null];
        }

        // 24h+ sem intervenção: starvation de fila é a única forma de PARADA PERMANENTE (o
        // supervisor sai com queue_starved/exhausted → status=completed → o respawn de morte
        // acima nunca o pega). Mas o loop MERGEIA código em main → a superfície de melhoria
        // muda + o backlog auto-feed injeta alvos novos → reviver periodicamente ACHA
        // trabalho. Revive campanhas starved-com-budget, throttled por `updated_at` (dá tempo
        // ao drain de 30min mudar o código), nunca abaixo do budget nem com kill-switch.
        $out['revived_starved'] = [];
        if ((bool) config('atlas.loop.keepalive_revive_starved', true)) {
            $reviveAfter = max(5, (int) config('atlas.loop.keepalive_starved_revive_minutes', 20));
            $starved = DB::table('atlas_loop_campaigns')
                ->where('status', 'completed')
                ->where('kill_switch', false)
                ->whereIn('stop_reason', ['queue_starved_no_refill', 'queue_exhausted'])
                ->whereColumn('elapsed_seconds', '<', 'max_seconds')
                // Só soaks REAIS de longa duração (não campanhas-teste de 60s) e RECENTES
                // (ativas nas últimas 48h) — nunca acorda artefato antigo/abandonado.
                ->where('max_seconds', '>=', 3600)
                ->where('updated_at', '>=', now()->subHours(48))
                ->get();
            foreach ($starved as $campaign) {
                $id = (string) $campaign->id;
                $out['checked']++;
                $touchedAgo = $campaign->updated_at ? (time() - strtotime((string) $campaign->updated_at)) : PHP_INT_MAX;
                if ($touchedAgo < $reviveAfter * 60 || $this->supervisorAlive($id)) {
                    continue; // throttle: ainda no cooldown, ou já vivo
                }
                // Volta a running e relança — o supervisor re-descobre (discovery + backlog).
                DB::table('atlas_loop_campaigns')->where('id', $id)
                    ->update(['status' => 'running', 'updated_at' => now()]);
                $this->respawn($id);
                $out['revived_starved'][] = ['campaign_id' => $id, 'prior_stop' => (string) $campaign->stop_reason, 'starved_minutes' => (int) floor($touchedAgo / 60)];
            }
        }

        $out['generated_at'] = now()->toIso8601String();
        AtlasLoopMorningDigestService::appendKeepaliveEvent($out);

        $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    protected function supervisorAlive(string $campaignId): bool
    {
        $p = new Process(['pgrep', '-f', $this->supervisorPattern($campaignId)], null, null, null, 10.0);
        $p->run();

        return trim($p->getOutput()) !== '';
    }

    protected function supervisorPattern(string $campaignId): string
    {
        return 'atlas:loop:campaign.*'.preg_quote($campaignId, '/');
    }

    /**
     * PIDs of the REAL php supervisor(s) for this campaign — the SINGLE filtered source used by
     * both the boot-time read and the kill. `pgrep -f` matches anything whose command line mentions
     * the campaign id, which includes shell watchers and the pgrep/ps helpers themselves; this keeps
     * only the actual `artisan atlas:loop:campaign` php invocation, so a transient monitoring loop
     * can never masquerade as the supervisor (start-time read) nor get SIGTERM'd in its place (kill).
     *
     * @return list<string>
     */
    protected function supervisorPids(string $campaignId): array
    {
        $p = new Process(['pgrep', '-f', $this->supervisorPattern($campaignId)], null, null, null, 10.0);
        $p->run();
        $pids = [];
        foreach (preg_split('/\s+/', trim($p->getOutput())) ?: [] as $pid) {
            if ($pid === '' || ! ctype_digit($pid)) {
                continue;
            }
            $cmdProc = new Process(['ps', '-p', $pid, '-o', 'command='], null, null, null, 10.0);
            $cmdProc->run();
            $command = trim($cmdProc->getOutput());
            if (! str_contains($command, 'artisan atlas:loop:campaign')) {
                continue; // not the supervisor invocation
            }
            if (preg_match('/(?:pgrep|\bgrep\b|\/zsh|\/bash|\bseq\b)/', $command) === 1) {
                continue; // a shell watcher / matcher, not the php process
            }
            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * Process-start epoch of the REAL php supervisor for this campaign (the oldest one if more than
     * one), or null if none / unparseable. Anchored on `ps -o etime=` ELAPSED time, NOT `lstart=`:
     * lstart prints locale wall-clock with no offset token, which the artisan-forced UTC runtime
     * (config/app.php) misreads via strtotime — a full local-UTC-offset skew (3h on this -0300 host)
     * that flipped genuinely-fresh supervisors to "stale" and recycled them every cadence. Elapsed
     * time is timezone-free by construction (now − elapsed), so boot epoch is exact on any host.
     */
    protected function supervisorStartedAt(string $campaignId): ?int
    {
        $now = time();
        $oldest = null;
        foreach ($this->supervisorPids($campaignId) as $pid) {
            $etime = new Process(['ps', '-p', $pid, '-o', 'etime='], null, null, null, 10.0);
            $etime->run();
            $epoch = self::bootEpochFromEtime(trim($etime->getOutput()), $now);
            if ($epoch === null) {
                continue;
            }
            $oldest = $oldest === null ? $epoch : min($oldest, $epoch);
        }

        return $oldest;
    }

    /**
     * Pure, timezone-free conversion of a `ps -o etime=` ELAPSED string to a boot epoch (now −
     * elapsed). Accepts the POSIX formats `MM:SS`, `HH:MM:SS`, and `[DD]D-HH:MM:SS`. Returns null on
     * any malformed input (fail-safe → the caller treats it as "no boot evidence" → never recycles).
     * Public + static so it is unit-testable without the live `ps` plumbing — the blind spot that
     * let the lstart timezone bug ship green.
     */
    public static function bootEpochFromEtime(string $etime, int $now): ?int
    {
        $etime = trim($etime);
        if ($etime === '') {
            return null;
        }
        $days = 0;
        if (str_contains($etime, '-')) {
            [$d, $etime] = explode('-', $etime, 2);
            if ($d === '' || ! ctype_digit($d)) {
                return null;
            }
            $days = (int) $d;
        }
        $parts = explode(':', $etime);
        if (count($parts) < 2 || count($parts) > 3) {
            return null;
        }
        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                return null;
            }
        }
        $secs = (int) array_pop($parts);
        $mins = (int) array_pop($parts);
        $hours = $parts !== [] ? (int) array_pop($parts) : 0;
        if ($secs >= 60 || $mins >= 60) {
            return null; // malformed ps output
        }

        return $now - ($days * 86400 + $hours * 3600 + $mins * 60 + $secs);
    }

    /** SIGTERM the REAL supervisor process(es) so respawn() can start a clean one. */
    protected function killSupervisor(string $campaignId): void
    {
        foreach ($this->supervisorPids($campaignId) as $pid) {
            (new Process(['kill', '-TERM', $pid], null, null, null, 10.0))->run();
        }
    }

    protected function respawn(string $campaignId): void
    {
        $php = $this->phpBinary();
        $artisan = base_path('artisan');
        $log = storage_path('logs/loop-keepalive-respawn.log');
        $cmd = sprintf(
            'nohup %s -d memory_limit=4096M %s atlas:loop:campaign --campaign-id=%s --workers=%d --scenarios=%d --sleep-seconds=5 >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($campaignId),
            $this->workers(),
            max(1, (int) $this->option('scenarios')),
            escapeshellarg($log),
        );
        // shell exec é deliberado: o supervisor precisa sobreviver ao keepalive (detach).
        (new Process(['bash', '-lc', $cmd], base_path(), null, null, 30.0))->run();
    }

    protected function phpBinary(): string
    {
        return AtlasPhpBinary::path();
    }

    protected function workers(): int
    {
        $raw = trim((string) ($this->option('workers') ?: ''));

        return $raw !== '' && ctype_digit($raw)
            ? max(1, (int) $raw)
            : max(1, (int) config('atlas.loop.campaign.workers', 1));
    }
}

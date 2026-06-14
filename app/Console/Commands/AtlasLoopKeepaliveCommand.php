<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
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
        $out = ['schema_version' => 'atlas.loop.keepalive.v1', 'checked' => 0, 'respawned' => [], 'healthy' => []];
        $staleMinutes = max(2, (int) $this->option('stale-minutes'));

        $campaigns = DB::table('atlas_loop_campaigns')
            ->where('status', 'running')
            ->where('kill_switch', false)
            ->whereColumn('elapsed_seconds', '<', 'max_seconds')
            ->get();

        foreach ($campaigns as $campaign) {
            $out['checked']++;
            $id = (string) $campaign->id;

            $heartbeat = $campaign->heartbeat_at ? strtotime((string) $campaign->heartbeat_at) : 0;
            $stale = $heartbeat < (time() - $staleMinutes * 60);
            $alive = $this->supervisorAlive($id);

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

    /** SIGTERM a FROZEN supervisor process so respawn() can start a clean one. */
    protected function killSupervisor(string $campaignId): void
    {
        $p = new Process(['pgrep', '-f', $this->supervisorPattern($campaignId)], null, null, null, 10.0);
        $p->run();
        foreach (preg_split('/\s+/', trim($p->getOutput())) ?: [] as $pid) {
            if ($pid !== '' && ctype_digit($pid)) {
                (new Process(['kill', '-TERM', $pid], null, null, null, 10.0))->run();
            }
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

<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use App\Support\AtlasPhpBinary;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The REAL {@see FleetDriver} — pgrep/kill/launchctl against the live machine. This is the only class in the
 * control plane that touches real processes, so two properties matter above all:
 *
 *   - PRECISE matching. Like the keepalive's supervisorPids, every candidate PID is re-checked against the
 *     exact `ps -o command=` so a shell watcher / grep helper can never be mistaken for an agent and SIGTERM'd
 *     in its place. The driver only ever touches the agent it was asked about.
 *   - stop() is DEFINITIVE for launchd agents: it `bootout`s the job (so launchd stops respawning it) AND
 *     kills the live process. A kill alone would be undone by launchd within seconds — the exact "it comes
 *     back by itself" the operator is killing.
 *
 * start() is conservative and only reached when the reconciler's HARD GATE is ON (default OFF), so under the
 * shipped fail-closed defaults it is dormant. spentUsd() is honest 0.0 (spend is not metered yet → the TTL
 * FREIO governs, not the budget FREIO).
 */
final class SystemFleetDriver implements FleetDriver
{
    /**
     * Per-agent process/launchd fingerprints. `pgrep` narrows by the broad pattern; `command_substr` is the
     * exact substring the real process command line must contain; `launchd` is the job label (null = not a
     * launchd-managed agent).
     *
     * @return array{pgrep:string,command_substr:string,launchd:?string}|null
     */
    private function fingerprint(string $agentKey): ?array
    {
        return match ($agentKey) {
            AtlasFleetCatalog::LOOP => [
                'pgrep' => 'atlas:loop:campaign',
                'command_substr' => 'artisan atlas:loop:campaign',
                'launchd' => null,
            ],
            AtlasFleetCatalog::AI_WORKER_CODEX => [
                'pgrep' => 'atlas:ai:work.*codex',
                'command_substr' => 'artisan atlas:ai:work',
                'launchd' => 'com.atlas.ai-worker.codex',
            ],
            AtlasFleetCatalog::AI_WORKER_CLAUDE => [
                'pgrep' => 'atlas:ai:work.*claude',
                'command_substr' => 'artisan atlas:ai:work',
                'launchd' => 'com.atlas.ai-worker.claude',
            ],
            AtlasFleetCatalog::FINANCE_STRATEGY_LOOP => [
                'pgrep' => 'atlas:finance:strategy-campaign-runner',
                'command_substr' => 'artisan atlas:finance:strategy-campaign-runner',
                'launchd' => 'com.atlas.finance.strategy-loop',
            ],
            AtlasFleetCatalog::MAC_AGENT => [
                'pgrep' => 'atlas:host-agent:work',
                'command_substr' => 'artisan atlas:host-agent:work',
                'launchd' => 'com.atlas.mac-agent',
            ],
            default => null,
        };
    }

    public function isAlive(string $agentKey): bool
    {
        return $this->pids($agentKey) !== [];
    }

    /** @return list<int> */
    public function pids(string $agentKey): array
    {
        $fp = $this->fingerprint($agentKey);
        if ($fp === null) {
            return [];
        }

        try {
            $p = new Process(['pgrep', '-f', $fp['pgrep']], null, null, null, 10.0);
            $p->run();
            $pids = [];
            foreach (preg_split('/\s+/', trim($p->getOutput())) ?: [] as $pid) {
                if ($pid === '' || ! ctype_digit($pid)) {
                    continue;
                }
                $cmdProc = new Process(['ps', '-p', $pid, '-o', 'command='], null, null, null, 10.0);
                $cmdProc->run();
                $command = trim($cmdProc->getOutput());
                if (! str_contains($command, $fp['command_substr'])) {
                    continue; // not the real agent invocation
                }
                if (preg_match('/(?:pgrep|\bgrep\b|\/zsh|\/bash|\bseq\b|\bps\b)/', $command) === 1) {
                    continue; // a shell watcher / matcher, not the agent process
                }
                $pids[] = (int) $pid;
            }

            return $pids;
        } catch (Throwable) {
            return [];
        }
    }

    public function startedAtEpoch(string $agentKey): ?int
    {
        $now = now()->timestamp;
        $oldest = null;
        foreach ($this->pids($agentKey) as $pid) {
            try {
                $etime = new Process(['ps', '-p', (string) $pid, '-o', 'etime='], null, null, null, 10.0);
                $etime->run();
                // Reuse the keepalive's timezone-free etime→epoch parser (no duplication).
                $epoch = AtlasLoopKeepaliveCommand::bootEpochFromEtime(trim($etime->getOutput()), $now);
                if ($epoch !== null) {
                    $oldest = $oldest === null ? $epoch : min($oldest, $epoch);
                }
            } catch (Throwable) {
                // ignore unparseable
            }
        }

        return $oldest;
    }

    public function spentUsd(string $agentKey): float
    {
        return 0.0; // honest: spend is not metered yet; the TTL FREIO governs, not budget
    }

    public function start(string $agentKey, ?string $targetRef): void
    {
        $fp = $this->fingerprint($agentKey);
        if ($fp === null) {
            return;
        }

        try {
            if ($agentKey === AtlasFleetCatalog::LOOP) {
                $this->startLoopCampaign($targetRef);

                return;
            }
            // launchd-managed agents: bootstrap (load) the job so launchd starts + keeps it.
            if ($fp['launchd'] !== null) {
                $plist = $this->plistPath($fp['launchd']);
                if ($plist !== null) {
                    (new Process(['launchctl', 'bootstrap', 'gui/'.$this->uid(), $plist], null, null, null, 20.0))->run();
                }
            }
        } catch (Throwable) {
            // a failed start is observed on the next tick (still dead → tried again); never throw
        }
    }

    public function stop(string $agentKey): void
    {
        $fp = $this->fingerprint($agentKey);
        if ($fp === null) {
            return;
        }

        try {
            // 1) For launchd agents, bootout FIRST so launchd stops respawning before we kill.
            if ($fp['launchd'] !== null) {
                $plist = $this->plistPath($fp['launchd']);
                $target = 'gui/'.$this->uid().'/'.$fp['launchd'];
                (new Process(['launchctl', 'bootout', $target], null, null, null, 20.0))->run();
                if ($plist !== null) {
                    (new Process(['launchctl', 'bootout', 'gui/'.$this->uid(), $plist], null, null, null, 20.0))->run();
                }
            }
            // 2) SIGTERM the precise live processes.
            foreach ($this->pids($agentKey) as $pid) {
                (new Process(['kill', '-TERM', (string) $pid], null, null, null, 10.0))->run();
            }
        } catch (Throwable) {
            // best-effort; the next tick re-checks liveness
        }
    }

    private function startLoopCampaign(?string $targetRef): void
    {
        $php = AtlasPhpBinary::path();
        $artisan = base_path('artisan');
        $log = storage_path('logs/agent-reconciler-loop.log');
        $campaignArg = $targetRef !== null && $targetRef !== '' ? ' --campaign-id='.escapeshellarg($targetRef) : '';
        $cmd = sprintf(
            'nohup %s -d memory_limit=4096M %s atlas:loop:campaign%s --sleep-seconds=5 >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            $campaignArg,
            escapeshellarg($log),
        );
        (new Process(['bash', '-lc', $cmd], base_path(), null, null, 30.0))->run();
    }

    private function plistPath(string $label): ?string
    {
        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
        if ($home === '') {
            return null;
        }
        $path = $home.'/Library/LaunchAgents/'.$label.'.plist';

        return is_file($path) ? $path : null;
    }

    private function uid(): string
    {
        try {
            $p = new Process(['id', '-u'], null, null, null, 5.0);
            $p->run();
            $uid = trim($p->getOutput());

            return ctype_digit($uid) ? $uid : '501';
        } catch (Throwable) {
            return '501';
        }
    }
}

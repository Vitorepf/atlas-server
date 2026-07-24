<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgentGovernance\AtlasAgentRegistry;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * THE single window into the fleet for the operator + the apps: every autonomous agent, what is actually
 * running, what the operator declared, and which account each spends. Read-only — it never starts/stops
 * anything, so it is safe to run anytime.
 */
final class AtlasAgentsStatusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:agents:status {--json} {--active : Only the agents actually running now}';

    protected $description = 'Show every autonomous Atlas agent: running/desired/off, which account it spends, uptime, TTL.';

    public function handle(AtlasAgentRegistry $registry): int
    {
        $snap = (bool) $this->option('active') ? $registry->active() : $registry->snapshot();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($snap));

            return self::SUCCESS;
        }

        $active = (int) $snap['active_count'];
        $accounts = implode(', ', $snap['spending_accounts'] ?: ['—']);
        $this->line('');
        $this->line($active > 0
            ? "  <fg=red;options=bold>🔴 {$active} agente(s) ATIVO(s) gastando: {$accounts}</>"
            : '  <fg=green;options=bold>✓ 0 agentes ativos — nada gastando conta.</>');
        $this->line("  Fleet master: <options=bold>{$snap['fleet_master']}</>");
        $this->line('');

        $rows = [];
        foreach ($snap['agents'] as $a) {
            $rows[] = [
                $a['key'],
                $this->statusCell((string) $a['status']),
                $a['desired'] ? 'on' : 'off',
                $a['account'],
                $a['uptime_seconds'] !== null ? $this->humanDuration((int) $a['uptime_seconds']) : '—',
                $a['ttl_remaining_seconds'] !== null ? $this->humanDuration((int) $a['ttl_remaining_seconds']) : '—',
            ];
        }
        $this->table(['Agent', 'Status', 'Desired', 'Account', 'Uptime', 'TTL left'], $rows);

        return self::SUCCESS;
    }

    private function statusCell(string $status): string
    {
        return match ($status) {
            'running' => '<fg=red;options=bold>running</>',
            'desired_dead' => '<fg=yellow>desired (dead)</>',
            default => '<fg=gray>off</>',
        };
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }

        return floor($seconds / 3600).'h'.(floor(($seconds % 3600) / 60)).'m';
    }
}

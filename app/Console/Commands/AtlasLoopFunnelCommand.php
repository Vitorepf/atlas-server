<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFunnelService;
use Illuminate\Console\Command;

/**
 * L3-1 · Funil do Loop: contagem por estágio (discovered→…→merged) com razões.
 * Read-only — instrumenta o flywheel sem efeito colateral.
 */
class AtlasLoopFunnelCommand extends Command
{
    protected $signature = 'atlas:loop:funnel
        {--campaign= : Escopar a um campaign_id (default: todos)}
        {--json : Saída JSON canônica}';

    protected $description = 'Funil instrumentado do Loop: onde cada unidade de trabalho parou (por estágio, com razões).';

    public function handle(AtlasLoopFunnelService $funnel): int
    {
        $snapshot = $funnel->snapshot(trim((string) $this->option('campaign')) ?: null);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info('Funil do Loop');
        foreach ($snapshot['stages'] as $stage => $count) {
            $this->components->twoColumnDetail((string) $stage, (string) $count);
        }
        $this->components->twoColumnDetail('— pending tasks', (string) $snapshot['branches']['pending_tasks']);
        $this->components->twoColumnDetail('— retired stale', (string) $snapshot['branches']['retired_stale']);
        $this->components->twoColumnDetail('certified→merged', (string) $snapshot['conversion']['certified_to_merged']);
        $this->line('');
        $this->components->warn((string) $snapshot['verdict']);

        return self::SUCCESS;
    }
}

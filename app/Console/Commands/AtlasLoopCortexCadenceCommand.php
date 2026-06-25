<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService;
use Illuminate\Console\Command;

final class AtlasLoopCortexCadenceCommand extends Command
{
    protected $signature = 'atlas:loop:cortex:cadence {--json} {--force}';

    protected $description = 'Daily/outcome-triggered Cortex scope-comprehension snapshot rebuild (master-switch gated).';

    public function handle(): int
    {
        $service = app()->bound(AtlasLoopComprehensionCadenceService::class)
            ? app(AtlasLoopComprehensionCadenceService::class)
            : new AtlasLoopComprehensionCadenceService();

        if (! AtlasLoopMasterSwitch::enabled() && ! (bool) $this->option('force')) {
            $envelope = [
                'schema' => AtlasLoopComprehensionCadenceService::SCHEMA,
                'ok' => false,
                'reason' => 'loop_master_off',
                'snapshot_path' => $service->snapshotPath(),
                'stale_before' => $service->isStale(),
            ];
            if ($this->option('json')) {
                $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES));
            } else {
                $this->info('skipped: loop_master_off');
            }

            return 0;
        }

        $result = $service->rebuild();
        $envelope = [
            'schema' => AtlasLoopComprehensionCadenceService::SCHEMA,
            'ok' => (bool) ($result['ok'] ?? false),
            'snapshot_path' => (string) ($result['snapshot_path'] ?? ''),
            'stale_before' => (bool) ($result['stale_before'] ?? false),
        ];
        if (isset($result['error'])) {
            $envelope['error'] = $result['error'];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('cadence ok=%s stale_before=%s path=%s', $envelope['ok'] ? 'true' : 'false', $envelope['stale_before'] ? 'true' : 'false', $envelope['snapshot_path']));
        }

        return $envelope['ok'] ? 0 : 1;
    }
}

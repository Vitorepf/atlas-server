<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServableHeartbeatService;
use Illuminate\Console\Command;

final class AtlasTaskServableHeartbeatCommand extends Command
{
    protected $signature = 'atlas:task:servable-heartbeat {--json : Print machine-readable JSON}';

    protected $description = 'Servable-heartbeat: reads servability and auto-fires reap → sweep → repair when queue is jammed.';

    public function handle(): int
    {
        $service = app()->bound(AtlasTaskServableHeartbeatService::class)
            ? app(AtlasTaskServableHeartbeatService::class)
            : new AtlasTaskServableHeartbeatService();

        $envelope = $service->tick();
        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'servable=%d claimable=%d status=%s actions=%s receipt=%s',
                $envelope['servable_now'],
                $envelope['claimable_depth'],
                $envelope['status'],
                implode(',', $envelope['actions_fired']) ?: '-',
                $envelope['receipt_path'],
            ));
        }

        return $envelope['ok'] ? 0 : 1;
    }
}

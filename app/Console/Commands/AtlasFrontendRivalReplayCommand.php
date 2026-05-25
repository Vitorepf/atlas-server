<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Console\Command;

class AtlasFrontendRivalReplayCommand extends Command
{
    protected $signature = 'atlas:frontend:replay
        {action=inspect : inspect or template}
        {--evidence= : Evidence directory for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect or scaffold Atlas Frontend external rival replay evidence.';

    public function handle(AtlasFrontendRivalReplayHarnessService $replay): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $replay->inspect((string) ($this->option('evidence') ?: '')),
            'template' => $replay->writeTemplate((string) ($this->option('output') ?: '')),
            default => [
                'schema_version' => AtlasFrontendRivalReplayHarnessService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Rival Replay: '.$payload['status']);
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}

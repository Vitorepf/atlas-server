<?php

namespace App\Console\Commands;

use App\Services\Ai\AiGatewayService;
use Illuminate\Console\Command;

class AiEnqueueCommand extends Command
{
    protected $signature = 'atlas:ai:enqueue
        {input : Operator input to enqueue}
        {--agent= : Force a specific skill slug}
        {--provider= : Provider key, e.g. claude_cli or codex_cli}
        {--priority=50 : Queue priority from 0 to 100}';

    protected $description = 'Enqueue a manual Atlas interaction.';

    public function handle(AiGatewayService $gateway): int
    {
        $trace = $gateway->enqueueInteraction((string) $this->argument('input'), [
            'source_type' => 'manual',
            'agent_slug' => $this->option('agent') ?: null,
            'provider' => $this->option('provider') ?: null,
            'priority' => (int) $this->option('priority'),
        ]);

        $this->info(json_encode([
            'trace_id' => $trace->id,
            'trace_key' => $trace->trace_key,
            'job_id' => $trace->job?->id,
            'status' => $trace->status,
            'agent' => $trace->agent_slug,
            'provider' => $trace->provider,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

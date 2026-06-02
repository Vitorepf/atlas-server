<?php

namespace App\Console\Commands;

use App\Services\Ai\AiWorker;
use App\Services\Ai\AiWorkerLogger;
use Illuminate\Console\Command;

class AiWorkCommand extends Command
{
    protected $signature = 'atlas:ai:work
        {--once : Stop after one polling pass}
        {--limit=0 : Maximum jobs to process before stopping. 0 means unlimited unless --once is set}
        {--sleep=3 : Seconds to sleep when queue is empty}
        {--provider= : Restrict worker to one provider}
        {--worker-id= : Override worker identifier}';

    protected $description = 'Run a local Atlas AI provider worker for desktop jobs.';

    public function handle(AiWorker $worker, AiWorkerLogger $logger): int
    {
        $workerId = (string) ($this->option('worker-id') ?: config('atlas.ai.worker_id', 'atlas-worker'));
        $provider = $this->option('provider') ?: null;
        $limit = (int) $this->option('limit');
        $once = (bool) $this->option('once');
        $sleep = max(1, (int) $this->option('sleep'));
        $processed = 0;
        $lastHeartbeatAt = null;

        $logger->event('worker_started', 'Atlas worker started.', provider: $provider, workerId: $workerId, metadata: [
            'provider' => $provider,
            'once' => $once,
            'limit' => $limit,
        ]);

        try {
            while (true) {
                $job = $worker->runNext($provider ?: null, $workerId);

                if ($job) {
                    $processed++;
                    $this->line(json_encode([
                        'job_id' => $job->id,
                        'status' => $job->status,
                        'attempts' => $job->attempts,
                        'provider' => $job->provider,
                    ], JSON_UNESCAPED_SLASHES));
                } elseif ($once) {
                    break;
                } else {
                    if ($lastHeartbeatAt === null || $lastHeartbeatAt->diffInSeconds(now(), true) >= 60) {
                        $logger->event('worker_heartbeat', 'Atlas worker alive.', provider: $provider, workerId: $workerId, metadata: [
                            'provider' => $provider,
                            'processed' => $processed,
                        ]);
                        $lastHeartbeatAt = now();
                    }

                    sleep($sleep);
                }

                if ($once || ($limit > 0 && $processed >= $limit)) {
                    break;
                }
            }
        } finally {
            $logger->event('worker_stopped', 'Atlas worker stopped.', provider: $provider, workerId: $workerId, metadata: [
                'processed' => $processed,
            ]);
        }

        $this->info(json_encode(['processed' => $processed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\MobileReliabilityMonitor;
use App\Services\Ai\Scheduling\AtlasCliSchedulerService;
use App\Services\Ai\Scheduling\AtlasSchedulerInput;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasSchedulerTickCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:scheduler:tick
        {--limit=25 : Maximum due tasks to claim}
        {--dry-run : Preview due tasks without claiming, advancing or dispatching}
        {--no-dispatch : Claim and advance tasks without dispatching jobs}
        {--json : Print machine-readable JSON}';

    protected $description = 'Claim due Atlas scheduled tasks and dispatch their execution jobs.';

    public function handle(AtlasCliSchedulerService $scheduler, AtlasSchedulerInput $input): int
    {
        if (! DatabaseTableAvailability::has('ai_scheduled_tasks')) {
            return $this->printPayload([
                'ok' => false,
                'error' => 'Tabela ai_scheduled_tasks ainda nao existe. Rode migrations.',
            ], self::FAILURE);
        }

        $dryRun = (bool) $this->option('dry-run');
        $dispatchEnabled = ! $dryRun && ! (bool) $this->option('no-dispatch');
        $limit = $input->dueTaskLimit($this->option('limit'));
        $result = $dryRun
            ? $scheduler->previewDueTasks(limit: $limit)
            : $scheduler->tick(
                limit: $limit,
                dispatch: $dispatchEnabled,
            );

        if (! $dryRun) {
            MobileReliabilityMonitor::recordSchedulerTick();
        }

        return $this->printPayload([
            'ok' => true,
            'dry_run' => $dryRun,
            'dispatch_enabled' => $dispatchEnabled,
            ...$result,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exitCode;
        }

        if (($payload['ok'] ?? false) === false) {
            $this->error((string) ($payload['error'] ?? 'Erro desconhecido.'));

            return $exitCode;
        }

        $count = ($payload['dry_run'] ?? false) ? ($payload['would_dispatch'] ?? 0) : ($payload['dispatched'] ?? 0);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Scheduler Tick</>', (string) $count.' task(s)');
        $this->components->twoColumnDetail('Mode', ($payload['dry_run'] ?? false) ? 'dry-run' : 'claim');
        $this->components->twoColumnDetail('Dispatch', ($payload['dispatch_enabled'] ?? false) ? 'enabled' : 'disabled');

        return $exitCode;
    }
}

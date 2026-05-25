<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRepairPlannerService;
use Illuminate\Console\Command;

class AtlasFrontendRepairPlanCommand extends Command
{
    protected $signature = 'atlas:frontend:repair-plan
        {--task= : Frontend task or brief}
        {--task-spec-hash= : Optional canonical task spec hash}
        {--blocker=* : Gate blocker id}
        {--warning=* : Gate warning id}
        {--failed-gate=* : Failed gate id}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero when no repair signal is provided}';

    protected $description = 'Compile a deterministic Atlas Frontend repair plan from failed gates and blockers.';

    public function handle(AtlasFrontendRepairPlannerService $planner): int
    {
        $payload = $planner->plan([
            'task' => (string) ($this->option('task') ?: ''),
            'task_spec_hash' => (string) ($this->option('task-spec-hash') ?: ''),
            'blockers' => (array) $this->option('blocker'),
            'warnings' => (array) $this->option('warning'),
            'failed_gates' => (array) $this->option('failed-gate'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Repair Plan: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }
}

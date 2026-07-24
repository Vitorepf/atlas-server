<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendDesignRuntimePlanCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:plan
        {--task= : Frontend task or user intent}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--workspace= : Workspace path to hash into the contract}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit the Atlas Frontend deterministic runtime contract for a frontend task.';

    public function handle(AtlasFrontendDesignRuntimeService $runtime): int
    {
        $payload = $runtime->contract([
            'task' => (string) ($this->option('task') ?? ''),
            'surface' => (string) ($this->option('surface') ?? 'programming.frontend'),
            'workspace' => (string) ($this->option('workspace') ?? ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $payload['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Atlas Frontend Runtime: '.($payload['status'] ?? 'unknown'));
        $this->line('Capabilities: '.count((array) ($payload['required_capabilities'] ?? [])));
        $this->line('Gates: '.count((array) ($payload['required_gates'] ?? [])));

        return $payload['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}

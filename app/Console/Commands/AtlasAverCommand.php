<?php

namespace App\Console\Commands;

use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use Illuminate\Console\Command;

class AtlasAverCommand extends Command
{
    protected $signature = 'atlas:aver
        {action=control-plane : plan|run-command|run-test|fixture-cycle|certify|control-plane}
        {--objective= : Execution objective}
        {--execution-id= : AVER execution UUID}
        {--command= : Safe command to run}
        {--cwd= : Working directory}
        {--domain=programming : Domain}
        {--flow-id=atlas_dev : Flow id}
        {--evidence=* : Evidence refs}
        {--simulate-test-failure : Simulate failing fixture test}
        {--hours=24 : Control plane window}
        {--json : Emit JSON}';

    protected $description = 'Operate AVER, the Atlas Verified Execution Runtime.';

    public function handle(AtlasVerifiedExecutionRuntimeService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'plan' => $runtime->plan($this->baseInput()),
            'run-command' => $runtime->runCommand(array_merge($this->baseInput(), ['execution_id' => $this->option('execution-id'), 'command' => $this->option('command'), 'cwd' => $this->option('cwd')])),
            'run-test' => $runtime->runTest(array_merge($this->baseInput(), ['execution_id' => $this->option('execution-id'), 'command' => $this->option('command'), 'cwd' => $this->option('cwd')])),
            'fixture-cycle' => $runtime->executeFixtureCycle(array_merge($this->baseInput(), ['simulate_test_failure' => (bool) $this->option('simulate-test-failure')])),
            'certify' => $runtime->certify(['execution_id' => $this->option('execution-id'), 'evidence_refs' => $this->option('evidence')]),
            'control-plane' => $runtime->controlPlane((int) $this->option('hours')),
            default => ['schema_version' => 'atlas.aver.command_error.v1', 'status' => 'blocked', 'reason' => 'unknown_action', 'action' => $action],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('AVER action', $action);
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return ($payload['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseInput(): array
    {
        return [
            'objective' => $this->option('objective') ?: 'AVER CLI execution',
            'domain' => $this->option('domain'),
            'flow_id' => $this->option('flow-id'),
            'evidence_refs' => $this->option('evidence'),
        ];
    }
}

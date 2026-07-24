<?php

namespace App\Console\Commands;

use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAverCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aver
        {action=control-plane : plan|plan-from-verified-evolution|run-command|run-test|fixture-cycle|certify|control-plane}
        {--objective= : Execution objective}
        {--execution-id= : AVER execution UUID}
        {--command= : Safe command to run}
        {--cwd= : Working directory}
        {--domain=programming : Domain}
        {--flow-id=atlas_dev : Flow id}
        {--contract-json= : AVEOR execution-contract JSON for plan-from-verified-evolution}
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
            'plan-from-verified-evolution' => $runtime->planFromVerifiedEvolutionContract($this->contractJson()),
            'run-command' => $runtime->runCommand(array_merge($this->baseInput(), ['execution_id' => $this->option('execution-id'), 'command' => $this->option('command'), 'cwd' => $this->option('cwd')])),
            'run-test' => $runtime->runTest(array_merge($this->baseInput(), ['execution_id' => $this->option('execution-id'), 'command' => $this->option('command'), 'cwd' => $this->option('cwd')])),
            'fixture-cycle' => $runtime->executeFixtureCycle(array_merge($this->baseInput(), ['simulate_test_failure' => (bool) $this->option('simulate-test-failure')])),
            'certify' => $runtime->certify(['execution_id' => $this->option('execution-id'), 'evidence_refs' => $this->option('evidence')]),
            'control-plane' => $runtime->controlPlane((int) $this->option('hours')),
            default => ['schema_version' => 'atlas.aver.command_error.v1', 'status' => 'blocked', 'reason' => 'unknown_action', 'action' => $action],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('AVER action', $action);
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->line($this->encode($payload));
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

    /**
     * @return array<string,mixed>
     */
    private function contractJson(): array
    {
        $json = trim((string) $this->option('contract-json'));
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}

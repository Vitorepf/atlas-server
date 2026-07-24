<?php

namespace App\Console\Commands;

use App\Services\Ai\RealitySandbox\AtlasAutonomousRealitySandboxService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAarsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aars
        {action=control-plane : run|scenario|control-plane}
        {--objective= : Scenario objective}
        {--domain= : Domain}
        {--flow-id= : Flow id}
        {--surface-id=atlas_simulation_chamber : Surface id}
        {--evidence=* : Evidence refs}
        {--hours=24 : Control plane window}
        {--force-critical : Force critical risk for certification/testing}
        {--json : Emit JSON}';

    protected $description = 'Operate AARS, the Atlas Autonomous Reality Sandbox.';

    public function handle(AtlasAutonomousRealitySandboxService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'run' => $runtime->run($this->inputPayload()),
            'scenario' => $runtime->createScenario($this->inputPayload()),
            'control-plane' => $runtime->controlPlane((int) $this->option('hours')),
            default => ['schema_version' => 'atlas.aars.command_error.v1', 'status' => 'blocked', 'reason' => 'unknown_action', 'action' => $action],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('AARS action', $action);
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->line($this->encode($payload));
        }

        return ($payload['status'] ?? null) === AtlasAutonomousRealitySandboxService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function inputPayload(): array
    {
        return [
            'objective' => $this->option('objective') ?: 'AARS CLI scenario',
            'domain' => $this->option('domain'),
            'flow_id' => $this->option('flow-id'),
            'surface_id' => $this->option('surface-id'),
            'evidence_refs' => $this->option('evidence'),
            'force_critical' => (bool) $this->option('force-critical'),
        ];
    }
}

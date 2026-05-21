<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use Illuminate\Console\Command;

class AtlasAaelCommand extends Command
{
    protected $signature = 'atlas:aael
        {action=control-plane : cycle|control-plane}
        {--objective= : Evolution objective}
        {--workspace= : Workspace root}
        {--domain=programming : Domain}
        {--flow-id=atlas_forge : Flow id}
        {--evidence=* : Evidence refs}
        {--hours=24 : Control plane window}
        {--json : Emit JSON}';

    protected $description = 'Operate AAEL, the Atlas Autonomous Evolution Loop.';

    public function handle(AtlasAutonomousEvolutionLoopService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'cycle' => $runtime->runCycle($this->baseInput()),
            'control-plane' => $runtime->controlPlane((int) $this->option('hours')),
            default => ['schema_version' => 'atlas.aael.command_error.v1', 'status' => 'blocked', 'reason' => 'unknown_action', 'action' => $action],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('AAEL action', $action);
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return ($payload['status'] ?? null) === AtlasAutonomousEvolutionLoopService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseInput(): array
    {
        return [
            'objective' => $this->option('objective') ?: 'AAEL CLI evolution cycle',
            'workspace' => $this->option('workspace') ?: base_path(),
            'domain' => $this->option('domain'),
            'flow_id' => $this->option('flow-id'),
            'evidence_refs' => $this->option('evidence'),
        ];
    }
}

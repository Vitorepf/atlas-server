<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopArmedCoverageReporter;
use Illuminate\Console\Command;

class AtlasAaelCommand extends Command
{
    protected $signature = 'atlas:aael
        {action=control-plane : cycle|control-plane|bridge-execute|armed-coverage}
        {--objective= : Evolution objective}
        {--workspace= : Workspace root}
        {--domain=programming : Domain}
        {--flow-id=atlas_forge : Flow id}
        {--evidence=* : Evidence refs}
        {--hours=24 : Control plane window}
        {--max-tasks=0 : Max tasks for bridge-execute}
        {--max-seconds=0 : Max seconds for bridge-execute}
        {--scenarios-per-task=0 : Scenarios per task for bridge-execute}
        {--propose-only=1 : Kept for compatibility; bridge-execute is always propose-only}
        {--json : Emit JSON}';

    protected $description = 'Operate AAEL, the Atlas Autonomous Evolution Loop.';

    public function handle(
        AtlasAutonomousEvolutionLoopService $runtime,
        AtlasAaelLoopExecutionBridge $bridge,
        AtlasLoopArmedCoverageReporter $armedCoverage,
    ): int {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'cycle' => $runtime->runCycle($this->baseInput()),
            'control-plane' => $runtime->controlPlane((int) $this->option('hours')),
            'bridge-execute' => $this->bridgeExecute($runtime, $bridge),
            'armed-coverage' => $this->armedCoverage($armedCoverage),
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
    private function bridgeExecute(AtlasAutonomousEvolutionLoopService $runtime, AtlasAaelLoopExecutionBridge $bridge): array
    {
        $payload = $runtime->runCycle($this->baseInput());
        $selected = is_array($payload['selected_opportunities'] ?? null) ? $payload['selected_opportunities'] : [];

        return [
            ...$payload,
            'bridge_execution' => $bridge->execute($selected, [
                'max_tasks' => (int) $this->option('max-tasks'),
                'max_seconds' => (int) $this->option('max-seconds'),
                'scenarios_per_task' => (int) $this->option('scenarios-per-task'),
                'propose_only' => true,
            ]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function armedCoverage(AtlasLoopArmedCoverageReporter $reporter): array
    {
        $report = $reporter->report();

        return [
            'schema_version' => AtlasLoopArmedCoverageReporter::SCHEMA,
            'status' => 'ok',
            'primitives_count' => count($report),
            'coverage' => $report,
        ];
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

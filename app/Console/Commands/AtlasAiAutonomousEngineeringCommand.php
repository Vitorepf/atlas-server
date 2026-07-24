<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiAutonomousEngineeringCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:autonomous-engineering
        {action=readiness : readiness, run, control-plane, certify}
        {--goal= : Goal text for run}
        {--context-sufficiency=82 : Simulated context sufficiency for the mandatory RAG gate}
        {--step-status=passed : Simulated work step status}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Autonomous Engineering OS readiness, execution, control plane and certification commands.';

    public function handle(AtlasAutonomousEngineeringService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $payload = match ($action) {
            'readiness', 'status' => $service->readiness(),
            'run', 'smoke' => $service->run($this->goalText(), [
                'context_sufficiency' => (int) $this->option('context-sufficiency'),
                'step_status' => (string) $this->option('step-status'),
            ]),
            'control-plane', 'control_plane' => $service->controlPlane(),
            'certify', 'certification' => $service->certify()->toArray(),
            default => [
                'schema_version' => 'atlas.ai.autonomous_engineering.command.v1',
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['readiness', 'run', 'control-plane', 'certify'],
                'writes' => false,
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->exitCodeFor($payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Autonomous Engineering OS</>', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Writes', YesNo::format($payload['writes'] ?? false));

        return $this->exitCodeFor($payload);
    }

    private function goalText(): string
    {
        return (string) ($this->option('goal') ?: 'implemente um smoke autônomo de engenharia com evidência');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCodeFor(array $payload): int
    {
        return in_array(($payload['status'] ?? null), ['failed', 'blocked'], true)
            || in_array(data_get($payload, 'certification.status'), ['failed', 'blocked'], true)
            || in_array(data_get($payload, 'rag_gate.status'), ['failed', 'blocked'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}

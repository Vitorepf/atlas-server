<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionService;
use Illuminate\Console\Command;

class AtlasAweosCommand extends Command
{
    protected $signature = 'atlas:aweos
        {action=control-plane : run|event|certify-outcome|control-plane}
        {--objective= : Objective/prompt to execute}
        {--domain= : Domain hint}
        {--flow-id= : Flow hint}
        {--execution-id= : Existing AWEOS execution id}
        {--event-type= : Event type}
        {--claim= : Certified outcome claim}
        {--evidence=* : Evidence refs}
        {--context=* : Context refs}
        {--quality-score= : Certified outcome quality score}
        {--hours=24 : Lookback window}
        {--json : Emit JSON}';

    protected $description = 'Operate AWEOS, the Atlas Autonomous Work Execution OS. Orchestrates existing runtimes; no direct provider calls.';

    public function handle(AtlasAutonomousWorkExecutionService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'run' => $runtime->run([
                'objective' => (string) ($this->option('objective') ?: ''),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow-id'),
                'evidence_refs' => (array) $this->option('evidence'),
                'context_refs' => (array) $this->option('context'),
                'source' => 'atlas:aweos',
            ]),
            'event' => $runtime->recordEvent([
                'execution_id' => $this->option('execution-id'),
                'event_type' => $this->option('event-type'),
                'payload' => ['source' => 'atlas:aweos'],
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            'certify-outcome' => $runtime->certifyOutcome([
                'execution_id' => $this->option('execution-id'),
                'claim' => $this->option('claim'),
                'quality_score' => $this->option('quality-score'),
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            default => $runtime->controlPlane((int) $this->option('hours')),
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return ($payload['status'] ?? null) === AtlasAutonomousWorkExecutionService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AWEOS action', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['execution_hash'] ?? $payload['event_hash'] ?? $payload['outcome_hash'] ?? $payload['control_plane_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasAutonomousWorkExecutionService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}

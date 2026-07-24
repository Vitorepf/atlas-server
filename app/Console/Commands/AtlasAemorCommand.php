<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor
        {action=control-plane : episode-open|observe|close-outcome|distill|memory-audit|replay|control-plane}
        {--episode= : AEMOR episode id}
        {--outcome= : AEMOR outcome id}
        {--objective= : Objective/prompt}
        {--workspace= : Workspace path}
        {--domain= : Domain}
        {--flow= : Flow id}
        {--provider= : Provider}
        {--status= : Outcome/event status}
        {--summary= : Outcome or learning summary}
        {--event-type= : Event type}
        {--evidence=* : Evidence refs}
        {--skill-candidate : During distill, propose a governed skill candidate from the outcome}
        {--hours=24 : Control-plane window}
        {--json : Emit JSON}';

    protected $description = 'Operate Atlas Execution Memory & Outcome Runtime.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'episode-open' => $runtime->openEpisode([
                'objective' => (string) ($this->option('objective') ?: ''),
                'workspace' => (string) ($this->option('workspace') ?: base_path()),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow'),
                'provider' => $this->option('provider'),
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            'observe' => $runtime->observe([
                'episode_id' => $this->option('episode'),
                'event_type' => $this->option('event-type') ?: 'manual_observation',
                'status' => $this->option('status') ?: 'observed',
                'payload' => ['summary' => $this->option('summary')],
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            'close-outcome' => $runtime->closeOutcome([
                'episode_id' => $this->option('episode'),
                'status' => $this->option('status') ?: 'succeeded',
                'summary' => (string) ($this->option('summary') ?: 'AEMOR outcome closed.'),
                'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            'distill' => $runtime->distill([
                'episode_id' => $this->option('episode'),
                'outcome_id' => $this->option('outcome'),
                'claim' => (string) ($this->option('summary') ?: 'AEMOR learning signal.'),
                'evidence_refs' => (array) $this->option('evidence'),
                'propose_skill_candidate' => (bool) $this->option('skill-candidate'),
            ]),
            'memory-audit' => $runtime->memoryAudit(),
            'replay' => $runtime->replayManifest((string) ($this->option('episode') ?: '')),
            'control-plane' => $runtime->controlPlane((int) $this->option('hours')),
            default => ['status' => 'blocked', 'error' => 'unknown_action'],
        };

        $this->emit($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return;
        }

        $this->components->twoColumnDetail('AEMOR', (string) ($payload['schema_version'] ?? 'unknown'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['episode_hash'] ?? $payload['outcome_hash'] ?? $payload['control_plane_hash'] ?? $payload['replay_hash'] ?? '-'));
    }
}

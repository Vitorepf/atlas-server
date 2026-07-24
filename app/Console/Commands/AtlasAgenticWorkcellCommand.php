<?php

namespace App\Console\Commands;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAgenticWorkcellCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:agentic-workcell
        {action=control-plane : design|event|outcome|control-plane}
        {--objective= : Objective/prompt to organize}
        {--domain= : Domain hint}
        {--flow-id= : Flow hint}
        {--topology= : Force a topology for testing/audit}
        {--evidence=* : Evidence refs}
        {--context=* : Context refs}
        {--workcell-id= : Existing workcell id}
        {--event-type= : Event type}
        {--quality-score= : Outcome quality score}
        {--coordination-roi-score= : Outcome coordination ROI score}
        {--hours=24 : Lookback window}
        {--json : Emit JSON}';

    protected $description = 'Operate AAWR, the Atlas Agentic Workcell Runtime. Planning only: no provider calls, no agent spawning.';

    public function handle(AtlasAgenticWorkcellRuntimeService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'design' => $runtime->design([
                'objective' => (string) ($this->option('objective') ?: ''),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow-id'),
                'topology' => $this->option('topology'),
                'evidence_refs' => (array) $this->option('evidence'),
                'context_refs' => (array) $this->option('context'),
                'source' => 'atlas:agentic-workcell',
            ]),
            'event' => $runtime->recordEvent([
                'workcell_id' => $this->option('workcell-id'),
                'event_type' => $this->option('event-type'),
                'evidence_refs' => (array) $this->option('evidence'),
                'payload' => ['source' => 'atlas:agentic-workcell'],
            ]),
            'outcome' => $runtime->closeOutcome([
                'workcell_id' => $this->option('workcell-id'),
                'quality_score' => $this->option('quality-score'),
                'coordination_roi_score' => $this->option('coordination-roi-score'),
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            default => $runtime->controlPlane((int) $this->option('hours')),
        };

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return ($payload['status'] ?? null) === AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AAWR action', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['workcell_hash'] ?? $payload['event_hash'] ?? $payload['outcome_hash'] ?? $payload['control_plane_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasRuntimeEfficiencyGovernorCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:runtime-efficiency
        {action=control-plane : govern|outcome|compile-policy|replay|control-plane}
        {--prompt= : Prompt/objective to govern}
        {--domain= : Domain hint}
        {--flow-id= : Flow hint}
        {--evidence=* : Evidence refs}
        {--decision-id= : Decision id for outcome}
        {--quality-score= : Outcome quality score}
        {--context-roi-score= : Outcome context ROI score}
        {--min-samples=3 : Minimum outcome samples for policy compilation}
        {--hours=24 : Lookback window for control-plane}
        {--json : Emit JSON}';

    protected $description = 'Operate AREG, the Atlas Runtime Efficiency Governor. No providers, no external execution.';

    public function handle(AtlasRuntimeEfficiencyGovernorService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'govern' => $runtime->govern([
                'prompt' => (string) ($this->option('prompt') ?: ''),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow-id'),
                'evidence_refs' => (array) $this->option('evidence'),
                'source' => 'atlas:runtime-efficiency',
            ]),
            'outcome' => $runtime->recordOutcome([
                'decision_id' => $this->option('decision-id'),
                'quality_score' => $this->option('quality-score'),
                'context_roi_score' => $this->option('context-roi-score'),
                'evidence_refs' => (array) $this->option('evidence'),
                'source' => 'atlas:runtime-efficiency',
            ]),
            'compile-policy' => $runtime->compilePolicy([
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow-id'),
                'hours' => $this->option('hours'),
                'min_samples' => $this->option('min-samples'),
                'source' => 'atlas:runtime-efficiency',
            ]),
            'replay' => $runtime->counterfactualReplay([
                'prompt' => (string) ($this->option('prompt') ?: ''),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow-id'),
                'decision_id' => $this->option('decision-id'),
                'evidence_refs' => (array) $this->option('evidence'),
                'source' => 'atlas:runtime-efficiency',
            ]),
            default => $runtime->controlPlane((int) $this->option('hours')),
        };

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return ($payload['status'] ?? null) === AtlasRuntimeEfficiencyGovernorService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AREG action', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['decision_hash'] ?? $payload['control_plane_hash'] ?? $payload['outcome_hash'] ?? $payload['policy_hash'] ?? $payload['replay_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasRuntimeEfficiencyGovernorService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}

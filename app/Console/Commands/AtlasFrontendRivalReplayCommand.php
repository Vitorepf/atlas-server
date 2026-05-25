<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Console\Command;

class AtlasFrontendRivalReplayCommand extends Command
{
    protected $signature = 'atlas:frontend:replay
        {action=inspect : inspect, template, runner-kit, evidence-worklist, score-template or external-receipt-template}
        {--evidence= : Evidence directory for inspect, evidence-worklist, score-template or external-receipt-template action}
        {--output= : Output directory for template/runner-kit, or output JSON path for evidence-worklist/score-template/external-receipt-template}
        {--case= : Replay case id for score-template/external-receipt-template}
        {--system= : Replay system id for score-template/external-receipt-template}
        {--reviewer-ref-hash= : Provider-safe reviewer reference hash for score-template}
        {--scoring-surface=manual_competitive_review : Scoring surface for score-template}
        {--execution-surface=external_rival_system : Execution surface for external-receipt-template}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect, scaffold or operationalize Atlas Frontend external rival replay evidence.';

    public function handle(AtlasFrontendRivalReplayHarnessService $replay): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $replay->inspect((string) ($this->option('evidence') ?: '')),
            'template' => $replay->writeTemplate((string) ($this->option('output') ?: '')),
            'runner-kit' => $replay->writeRunnerKit((string) ($this->option('output') ?: '')),
            'evidence-worklist' => $replay->writeEvidenceWorklist((string) ($this->option('evidence') ?: ''), $this->nullableOutput()),
            'score-template' => $replay->writeScoreAttestationTemplate(
                (string) ($this->option('evidence') ?: ''),
                (string) ($this->option('case') ?: ''),
                (string) ($this->option('system') ?: ''),
                $this->nullableOutput(),
                (string) ($this->option('reviewer-ref-hash') ?: ''),
                (string) ($this->option('scoring-surface') ?: ''),
            ),
            'external-receipt-template' => $replay->writeExternalExecutionReceiptTemplate(
                (string) ($this->option('evidence') ?: ''),
                (string) ($this->option('case') ?: ''),
                (string) ($this->option('system') ?: ''),
                $this->nullableOutput(),
                (string) ($this->option('execution-surface') ?: ''),
            ),
            default => [
                'schema_version' => AtlasFrontendRivalReplayHarnessService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Rival Replay: '.$payload['status']);
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function nullableOutput(): ?string
    {
        $output = (string) ($this->option('output') ?? '');
        $output = trim($output);

        return $output === '' ? null : $output;
    }
}

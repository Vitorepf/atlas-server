<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use Illuminate\Console\Command;

final class AtlasRetrievalFeedbackLoopCommand extends Command
{
    protected $signature = 'atlas:context:retrieval-feedback
        {--query= : Objective/query to evaluate retrieval feedback for}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--outcome=passed : Execution outcome status}
        {--max-refs=8 : Maximum refs to rank before feedback}
        {--record : Persist an ai_rag_feedback_events row}
        {--json : Emit canonical JSON}';

    protected $description = 'Capture AUCRI ARFL retrieval feedback and proposal-only learning candidates.';

    public function handle(AtlasRetrievalFeedbackLoopService $service): int
    {
        $payload = $service->capture([
            'objective' => (string) ($this->option('query') ?: 'atlas retrieval feedback readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
            'outcome_status' => (string) $this->option('outcome'),
            'max_refs' => (int) $this->option('max-refs'),
            'record' => (bool) $this->option('record'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Retrieval Feedback Loop', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('ROI', (string) data_get($payload, 'context_roi.roi_score', 'unknown'));
        $this->components->twoColumnDetail('Learning candidate', (string) data_get($payload, 'learning_candidate.status', 'unknown'));
        $this->components->twoColumnDetail('Persisted', data_get($payload, 'persistence.persisted') ? 'yes' : 'no');

        return self::SUCCESS;
    }
}

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
        {--delivered-ref=* : Provider-safe context ref known to be delivered in the initial pack}
        {--used-ref=* : Provider-safe context ref or hash used during execution}
        {--noise-ref=* : Provider-safe context ref or hash judged noisy after execution}
        {--missed-source=* : Required source type missing from the initial pack}
        {--utility= : Post-execution context utility score from 0 to 100}
        {--record : Persist an ai_rag_feedback_events row}
        {--json : Emit canonical JSON}';

    protected $description = 'Capture AUCRI ARFL retrieval feedback and proposal-only learning candidates.';

    public function handle(AtlasRetrievalFeedbackLoopService $service): int
    {
        $input = [
            'objective' => (string) ($this->option('query') ?: 'atlas retrieval feedback readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
            'outcome_status' => (string) $this->option('outcome'),
            'max_refs' => (int) $this->option('max-refs'),
            'delivered_context_refs' => (array) $this->option('delivered-ref'),
            'used_context_refs' => (array) $this->option('used-ref'),
            'noise_context_refs' => (array) $this->option('noise-ref'),
            'missed_required_sources' => array_values(array_map(
                static fn (mixed $source): array => ['source_type' => (string) $source, 'reason' => 'cli_reported_missing_source'],
                (array) $this->option('missed-source'),
            )),
            'record' => (bool) $this->option('record'),
        ];

        if ($this->option('utility') !== null) {
            $input['post_execution_utility'] = (int) $this->option('utility');
        }

        $payload = $service->capture($input);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Retrieval Feedback Loop', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('ROI', (string) data_get($payload, 'context_roi.roi_score', 'unknown'));
        $this->components->twoColumnDetail('Context policy', (string) data_get($payload, 'next_context_policy.recommended_action', 'unknown'));
        $this->components->twoColumnDetail('Learning candidate', (string) data_get($payload, 'learning_candidate.status', 'unknown'));
        $this->components->twoColumnDetail('Persisted', data_get($payload, 'persistence.persisted') ? 'yes' : 'no');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextRankingSystemCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:rank
        {--query= : Objective/query to rank context for}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--max-refs=8 : Maximum refs to select}
        {--flow-id= : Optional flow id used to load latest retrieval feedback}
        {--feedback-repromote-source=* : Source type to promote from retrieval feedback}
        {--feedback-demote-source=* : Source type to demote from retrieval feedback}
        {--feedback-demote-hash=* : Source ref hash to demote from retrieval feedback}
        {--feedback-demote-ref=* : Provider-safe context ref to demote from retrieval feedback}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the AUCRI ACRS context ranking report with explainable scores.';

    public function handle(AtlasContextRankingSystemService $service): int
    {
        $input = [
            'objective' => (string) ($this->option('query') ?: 'atlas context ranking readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
            'max_refs' => (int) $this->option('max-refs'),
            'feedback_hint_input' => [
                'repromote_source_types' => (array) $this->option('feedback-repromote-source'),
                'demote_source_types' => (array) $this->option('feedback-demote-source'),
                'demote_source_hashes' => (array) $this->option('feedback-demote-hash'),
                'demote_context_refs' => (array) $this->option('feedback-demote-ref'),
            ],
        ];

        if ($this->option('flow-id') !== null) {
            $input['flow_id'] = (string) $this->option('flow-id');
        }

        $payload = $service->rank($input);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Ranking System', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Selected refs', (string) data_get($payload, 'rerank_result.metrics.selected_count', 0));
        $this->components->twoColumnDetail('Excluded refs', (string) data_get($payload, 'rerank_result.metrics.excluded_count', 0));
        $this->components->twoColumnDetail('Feedback hint', (string) data_get($payload, 'source_ranking_inputs.feedback_hint.status', 'inactive'));
        $this->components->twoColumnDetail('Feedback changed selection', data_getYesNo::format($payload, 'rerank_result.feedback_impact_report.selected_set_changed'));
        $this->components->twoColumnDetail('Rerank hash', (string) $payload['rerank_result_hash']);

        return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}

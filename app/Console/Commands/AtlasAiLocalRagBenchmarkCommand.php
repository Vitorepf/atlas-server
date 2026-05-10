<?php

namespace App\Console\Commands;

use App\Services\Ai\Context\LocalRagBenchmarkService;
use Illuminate\Console\Command;

final class AtlasAiLocalRagBenchmarkCommand extends Command
{
    protected $signature = 'atlas:ai:local-rag-benchmark {--json : Print machine-readable JSON}';

    protected $description = 'Run a controlled Local RAG router benchmark before Graph RAG/Python runtime promotion.';

    public function handle(LocalRagBenchmarkService $benchmark): int
    {
        $payload = $benchmark->report();
        $payload['evidence_ledger'] = $benchmark->evidenceLedgerReport($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Local RAG Benchmark</>', $payload['status']);
        $this->components->twoColumnDetail('Cases', $payload['passed_case_count'].'/'.$payload['case_count']);
        $this->components->twoColumnDetail('Average score', (string) $payload['average_score']);
        $this->components->twoColumnDetail('Quality corpus', (string) data_get($payload, 'quality_corpus.status'));
        $this->components->twoColumnDetail('Graph RAG promotion', data_get($payload, 'promotion_gate.graph_rag_promotion_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Promotion review', (string) data_get($payload, 'promotion_review_contract.status'));
        $this->components->twoColumnDetail('Auto promotion', data_get($payload, 'promotion_review_contract.auto_promotion_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Ledger evidence', (string) data_get($payload, 'evidence_ledger.status'));
        $this->components->twoColumnDetail('Remaining prereqs', implode(', ', data_get($payload, 'promotion_gate.remaining_prerequisites', [])));
        $this->components->twoColumnDetail('Next action', $payload['next_action']);

        return self::SUCCESS;
    }
}

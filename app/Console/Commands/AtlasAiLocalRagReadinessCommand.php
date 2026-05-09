<?php

namespace App\Console\Commands;

use App\Services\Ai\Context\LocalRagReadinessService;
use Illuminate\Console\Command;

final class AtlasAiLocalRagReadinessCommand extends Command
{
    protected $signature = 'atlas:ai:local-rag-readiness {--json : Print machine-readable JSON}';

    protected $description = 'Report Local RAG readiness without creating a parallel memory/runtime brain.';

    public function handle(LocalRagReadinessService $readiness): int
    {
        $payload = $readiness->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Local RAG Readiness</>', $payload['status']);
        $this->components->twoColumnDetail('Embedding', data_get($payload, 'embedding.provider'));
        $this->components->twoColumnDetail('Semantic notes', data_get($payload, 'stores.semantic_notes.table_exists') ? 'ready' : 'missing');
        $this->components->twoColumnDetail('Vector native', data_get($payload, 'stores.semantic_notes.vector_search_native') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next action', $payload['next_action']);

        if (($payload['blocking_gates'] ?? []) !== []) {
            $this->warn('Blocking gates: '.implode(', ', $payload['blocking_gates']));
        }

        return self::SUCCESS;
    }
}

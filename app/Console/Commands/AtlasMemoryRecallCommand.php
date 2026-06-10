<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasHybridMemoryRetrievalService;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasMemoryRecallCommand extends Command
{
    protected $signature = 'atlas:memory:recall
        {query?* : Query text}
        {--workspace= : Workspace path}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--session-id= : Session UUID}
        {--user-id= : User id}
        {--type=* : Registry memory type filter}
        {--limit=10 : Recall item limit}
        {--budget=2400 : Total recall character budget}
        {--item-chars=360 : Character budget per item}
        {--no-registry : Exclude central registry}
        {--no-verbatim : Exclude verbatim recall}
        {--no-semantic : Exclude semantic notes}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run provider-safe hybrid Atlas memory recall across registry, verbatim and semantic notes.';

    public function handle(AtlasHybridMemoryRetrievalService $retrieval): int
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries') && ! DatabaseTableAvailability::has('atlas_verbatim_memories') && ! DatabaseTableAvailability::has('semantic_notes')) {
            $this->error('Nenhuma tabela de memoria esta disponivel. Rode migrations.');

            return self::FAILURE;
        }

        $payload = $retrieval->recall(
            trim(implode(' ', array_map('strval', (array) $this->argument('query')))),
            $this->recallContext(),
            [
                'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
            ],
            [
                'limit' => (int) $this->option('limit'),
                'budget_chars' => (int) $this->option('budget'),
                'item_chars' => (int) $this->option('item-chars'),
                'include_registry' => ! (bool) $this->option('no-registry'),
                'include_verbatim' => ! (bool) $this->option('no-verbatim'),
                'include_semantic' => ! (bool) $this->option('no-semantic'),
            ],
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['memory_recall' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Recall</>', (string) data_get($payload, 'summary.recall_count', 0));
        $this->components->twoColumnDetail('Policy', (string) data_get($payload, 'summary.policy', 'provider_safe_only'));
        $this->components->twoColumnDetail('Redacted refs', (string) data_get($payload, 'summary.redacted_ref_count', 0));
        $this->components->twoColumnDetail('Raw content persisted', (string) data_get($payload, 'summary.raw_content_persisted_count', 0));
        $this->table(
            ['rank', 'source', 'type', 'score', 'title', 'excerpt'],
            collect((array) ($payload['recall'] ?? []))->map(fn (array $row): array => [
                $row['rank'] ?? '-',
                $row['source'] ?? '-',
                $row['type'] ?? '-',
                $row['score'] ?? '-',
                Str::limit((string) ($row['title'] ?? ''), 48),
                Str::limit((string) ($row['excerpt'] ?? ''), 96),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function recallContext(): array
    {
        return array_filter([
            'workspace' => $this->stringOption('workspace') ?: (getcwd() ?: null),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'session_id' => $this->stringOption('session-id'),
            'user_id' => $this->stringOption('user-id'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

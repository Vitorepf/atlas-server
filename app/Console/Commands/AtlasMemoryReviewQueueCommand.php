<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasMemoryReviewQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasMemoryReviewQueueCommand extends Command
{
    protected $signature = 'atlas:memory:review-queue
        {--area=* : memory, verbatim or relations}
        {--scope-type= : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--privacy= : normal, private, sensitive or secret}
        {--privacy-class= : Alias for --privacy}
        {--relation-type=* : duplicate or conflict}
        {--relation-status=open : open, resolved or dismissed}
        {--include-unreviewed : Include normal entries that have not been explicitly reviewed}
        {--include-inactive : Include inactive and archived memories}
        {--limit=50 : Maximum queue items}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show the unified Atlas memory review queue for privacy, verbatim and relation work.';

    public function handle(AtlasMemoryReviewQueueService $queue): int
    {
        $payload = [
            'review_queue' => $queue->queue($this->filters(), (int) $this->option('limit')),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($payload['review_queue']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $queue
     */
    private function render(array $queue): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Review Queue</>', (string) ($queue['total'] ?? 0).' item(s)');
        $this->components->twoColumnDetail('Memory privacy', (string) data_get($queue, 'counts.memory_privacy', 0));
        $this->components->twoColumnDetail('Verbatim privacy', (string) data_get($queue, 'counts.verbatim_privacy', 0));
        $this->components->twoColumnDetail('Relations', (string) data_get($queue, 'counts.relation', 0));

        $this->table(
            ['priority', 'kind', 'scope', 'target', 'reason', 'action'],
            collect((array) ($queue['items'] ?? []))->map(fn (array $item): array => [
                (string) ($item['priority'] ?? '-'),
                (string) ($item['kind'] ?? '-'),
                Str::limit((string) ($item['scope'] ?? data_get($item, 'source_memory.scope', '-')), 42),
                Str::limit((string) ($item['title'] ?? data_get($item, 'source_memory.title', $item['id'] ?? '-')), 42),
                Str::limit((string) ($item['reason'] ?? '-'), 68),
                Str::limit((string) ($item['action_hint'] ?? '-'), 74),
            ])->all(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'areas' => array_values(array_filter((array) $this->option('area'), 'is_string')),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'privacy_class' => $this->stringOption('privacy-class') ?: $this->stringOption('privacy'),
            'relation_types' => array_values(array_filter((array) $this->option('relation-type'), 'is_string')),
            'relation_status' => $this->stringOption('relation-status') ?: 'open',
            'include_unreviewed' => (bool) $this->option('include-unreviewed'),
            'include_inactive' => (bool) $this->option('include-inactive'),
        ];
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasOpenBrainContextCommand extends Command
{
    protected $signature = 'atlas:open-brain:context
        {objective* : Objective or task input}
        {--workspace= : Workspace path}
        {--task-type=dev : direct, dev, debug, review, research, decision or memory}
        {--desired-mode=direct : Atlas workflow mode}
        {--agent=orquestrador : Requested agent/domain}
        {--intent=memory_context_export : Intent label}
        {--requester=atlas-cli : Requester/tool label for audit}
        {--payload-json= : Extra payload JSON}
        {--include-prompt : Include rendered prompt section}
        {--json : Print machine-readable JSON}';

    protected $description = 'Export an audited Atlas Open Brain context pack for local tools and providers.';

    public function handle(AtlasOpenBrainService $brain): int
    {
        $objective = trim(implode(' ', array_map('strval', (array) $this->argument('objective'))));
        if ($objective === '') {
            $this->error('Informe o objetivo para exportar o context pack.');

            return self::FAILURE;
        }

        $payload = $this->payloadJson();
        if ($payload === null) {
            return self::FAILURE;
        }

        $result = $brain->contextPack([
            'objective' => $objective,
            'workspace' => $this->stringOption('workspace') ?: (getcwd() ?: null),
            'task_type' => $this->stringOption('task-type') ?: 'dev',
            'desired_mode' => $this->stringOption('desired-mode') ?: 'direct',
            'agent' => $this->stringOption('agent') ?: 'orquestrador',
            'intent' => $this->stringOption('intent') ?: 'memory_context_export',
            'requester' => $this->stringOption('requester') ?: 'atlas-cli',
            'include_prompt' => (bool) $this->option('include-prompt'),
            'payload' => $payload,
        ], 'cli');

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['open_brain' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Open Brain</>', (string) ($result['context_pack_hash'] ?? '-'));
        $this->components->twoColumnDetail('Recall', (string) data_get($result, 'summary.recall_count', 0));
        $this->components->twoColumnDetail('Context refs', (string) data_get($result, 'summary.context_refs_count', 0));
        $this->components->twoColumnDetail('Audit', (string) data_get($result, 'audit.id', 'unavailable'));

        foreach ((array) data_get($result, 'context_pack.memory.recall', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $this->line('#'.($item['rank'] ?? '?').' '.Str::limit((string) ($item['title'] ?? 'memoria'), 72));
            $this->line('  '.Str::limit((string) ($item['excerpt'] ?? ''), 140));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function payloadJson(): ?array
    {
        $raw = $this->stringOption('payload-json');
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error('payload-json invalido.');

            return null;
        }

        return $decoded;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

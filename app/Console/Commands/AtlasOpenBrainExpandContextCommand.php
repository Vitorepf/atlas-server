<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainContextExpansionService;
use Illuminate\Console\Command;

final class AtlasOpenBrainExpandContextCommand extends Command
{
    protected $signature = 'atlas:open-brain:expand-context
        {handle : Expansion handle such as expand:evidence_replay or recheck:canonical_doc}
        {objective* : Objective or task input}
        {--workspace= : Workspace path or id}
        {--task-type=dev : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--max-refs=6 : Maximum refs for ranking-backed expansion}
        {--budget=3200 : Budget for pack-backed expansion}
        {--json : Print machine-readable JSON}';

    protected $description = 'Expand one Open Brain context handle on demand. Read-only, provider-safe, local-only, zero provider spend.';

    public function handle(AtlasOpenBrainContextExpansionService $expansion): int
    {
        $objective = trim(implode(' ', array_map('strval', (array) $this->argument('objective'))));
        $payload = $expansion->expand([
            'handle' => (string) ($this->argument('handle') ?? ''),
            'objective' => $objective,
            'workspace' => $this->stringOption('workspace') ?: (getcwd() ?: null),
            'task_type' => $this->stringOption('task-type') ?: 'dev',
            'domain' => $this->stringOption('domain') ?: 'atlas',
            'risk_level' => $this->stringOption('risk') ?: 'low',
            'max_refs' => $this->integerOption('max-refs', 6),
            'budget' => $this->integerOption('budget', 3200),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line((string) ($payload['markdown'] ?? ''));

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integerOption(string $key, int $default): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}

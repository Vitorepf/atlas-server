<?php

namespace App\Console\Commands;

use App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasMemoryGovernanceCommand extends Command
{
    use EmitsCanonicalJson;

    private ?MemoryQueryInput $memoryInput = null;

    protected $signature = 'atlas:memory:govern
        {action=scan : scan}
        {--type=* : Filter by memory type}
        {--scope-type= : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--limit=200 : Maximum active memories to scan}
        {--dry-run : Detect without writing relations or status changes}
        {--json : Print machine-readable JSON}';

    protected $description = 'Govern Atlas memory registry health, duplicates and conflicts.';

    public function handle(AtlasMemoryGovernanceService $governance, MemoryQueryInput $input): int
    {
        $this->memoryInput = $input;

        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            $this->error('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = trim(strtolower((string) $this->argument('action')));
        if ($action !== 'scan') {
            $this->error("Acao invalida para atlas:memory:govern: {$action}");

            return self::FAILURE;
        }

        $payload = [
            'governance' => $governance->scan($this->filters(), $this->memoryInput()->governanceScanLimit($this->option('limit')), (bool) $this->option('dry-run')),
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $scan = $payload['governance'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Governance</>', 'scan');
        $this->components->twoColumnDetail('Scanned', (string) ($scan['scanned'] ?? 0));
        $this->components->twoColumnDetail('Duplicates', (string) count((array) ($scan['duplicates'] ?? [])));
        $this->components->twoColumnDetail('Conflicts', (string) count((array) ($scan['conflicts'] ?? [])));

        $this->table(
            ['type', 'source', 'target', 'reason'],
            collect((array) ($scan['duplicates'] ?? []))
                ->map(fn (array $row): array => [
                    'duplicate',
                    $row['duplicate_memory_entry_id'] ?? '-',
                    $row['canonical_memory_entry_id'] ?? '-',
                    $row['reason'] ?? '-',
                ])
                ->merge(collect((array) ($scan['conflicts'] ?? []))->map(fn (array $row): array => [
                    'conflict',
                    $row['source_memory_entry_id'] ?? '-',
                    $row['target_memory_entry_id'] ?? '-',
                    $row['reason'] ?? '-',
                ]))
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
        ];
    }


    private function memoryInput(): MemoryQueryInput
    {
        return $this->memoryInput ?? app(MemoryQueryInput::class);
    }
}

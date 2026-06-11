<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasAutonomousChangeOrchestratorService;
use Illuminate\Console\Command;

final class AtlasAutonomousChangeOrchestratorCommand extends Command
{
    protected $signature = 'atlas:autonomous-change-orchestrator
        {action=plan : plan}
        {--objective= : Change objective}
        {--target= : Path, symbol or runtime target}
        {--changed-file=* : Changed file to simulate against the boundary}
        {--max-repair-attempts=3 : Maximum repair attempts}
        {--include-contracts : Include full AVEOR/APDR/AVER contracts instead of compact summaries}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Compose contract-first autonomous change orchestration over Impact GraphRAG, AVEOR and AVER.';

    public function handle(AtlasAutonomousChangeOrchestratorService $service): int
    {
        $action = (string) $this->argument('action');
        if ($action !== 'plan') {
            $this->error('Unknown action. Expected plan.');

            return self::FAILURE;
        }

        $payload = $service->plan(
            (string) ($this->option('objective') ?: ''),
            (string) ($this->option('target') ?: ''),
            [
                'changed_files' => (array) $this->option('changed-file'),
                'max_repair_attempts' => (int) ($this->option('max-repair-attempts') ?: 3),
                'include_contracts' => (bool) $this->option('include-contracts'),
            ],
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Autonomous Change Orchestrator', (string) $payload['status']);
        $this->components->twoColumnDetail('Target', (string) ($payload['target'] ?? ''));
        $this->components->twoColumnDetail('Mutation Authorized', data_get($payload, 'orchestrator.mutation_authorized') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Hash', (string) ($payload['orchestration_hash'] ?? ''));

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}

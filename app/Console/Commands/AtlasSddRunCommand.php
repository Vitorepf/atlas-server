<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyUntrimmedStringOption;
use App\Services\Ai\Programming\Sdd\AtlasSddPipeline;
use App\Services\Ai\Programming\Sdd\Enums\AutonomyLevel;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use Illuminate\Console\Command;
use Throwable;

/**
 * End-to-end SDD pipeline runner. Useful for smoke validation and CI.
 */
class AtlasSddRunCommand extends Command
{
    use ReadsNonEmptyUntrimmedStringOption;

    protected $signature = 'atlas:sdd:run
        {intent : Plain-text intent}
        {--workspace= : Workspace path bound to the OperationEnvelope}
        {--user= : Optional user id}
        {--autonomy= : Autonomy override (L0_manual|L1_assisted|L2_auto_patch|L3_auto_pr|L4_restricted_merge|L5_proposal_only)}
        {--write=* : Repeatable proposed write entries in the form path::contents}
        {--command=* : Repeatable validation commands (recorded only by default)}
        {--strict : Exit non-zero unless the pipeline reaches `completed`}
        {--json : Print JSON output}';

    protected $description = 'Run the full Atlas SDD pipeline (intent → spec → plan → tasks → receipt → execute → drift → learning).';

    public function handle(AtlasSddPipeline $pipeline): int
    {
        try {
            $envelope = new OperationEnvelope(
                rawInput: (string) $this->argument('intent'),
                userId: $this->untrimmedStringOption('user'),
                workspace: $this->untrimmedStringOption('workspace'),
            );
            $autonomy = $this->parseAutonomy();
            $writes = $this->parseWrites();
            $commands = array_values(array_filter((array) $this->option('command'), 'is_string'));

            $output = $pipeline->run(
                envelope: $envelope,
                proposedWrites: $writes,
                proposedCommands: $commands,
                autonomy: $autonomy,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($output->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas SDD</>', $output->status);
            $this->components->twoColumnDetail('Operation', (string) ($output->payload['operation_id'] ?? '-'));
            $this->components->twoColumnDetail('Spec', (string) ($output->payload['spec_id'] ?? '-'));
            $this->components->twoColumnDetail('Plan', (string) ($output->payload['plan_id'] ?? '-'));
            $this->components->twoColumnDetail('Receipt', (string) ($output->payload['receipt_id'] ?? '-'));
            $this->components->twoColumnDetail('Autonomy', (string) ($output->payload['autonomy_level'] ?? '-'));
            $this->components->twoColumnDetail('Drift', (string) data_get($output->payload, 'drift.status', '-'));
        }

        if ((bool) $this->option('strict') && $output->status !== 'completed') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }


    private function parseAutonomy(): ?AutonomyLevel
    {
        $value = $this->untrimmedStringOption('autonomy');
        if ($value === null) {
            return null;
        }

        return AutonomyLevel::tryFrom($value);
    }

    /**
     * @return list<array{path:string,contents:string}>
     */
    private function parseWrites(): array
    {
        $writes = [];
        foreach ((array) $this->option('write') as $entry) {
            if (! is_string($entry) || ! str_contains($entry, '::')) {
                continue;
            }
            [$path, $contents] = explode('::', $entry, 2);
            if (trim($path) !== '') {
                $writes[] = ['path' => $path, 'contents' => $contents];
            }
        }

        return $writes;
    }
}

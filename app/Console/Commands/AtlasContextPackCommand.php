<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use Illuminate\Console\Command;

/**
 * AOBG N1.F1 — `atlas:context-pack`: the unified context-pack front door from the CLI.
 *
 * Mirrors `atlas:ctx` (the code-graph-only retrieval) but assembles the FULL fused,
 * provider-bound pack — code-graph + AURG reality graph + semantic memory — via
 * {@see AtlasOpenBrainContextPackService}. This is the operator/provider surface for
 * "what does the brain already know about this task?" in ANY project:
 *
 *     atlas:context-pack "workspace identity resolver" --workspace=/path/to/project
 *     atlas:context-pack "embedding decision" --budget=6000 --json
 *
 * Read-only, local DB only, zero provider spend. Fail-safe (house contract): the
 * service NEVER throws and each section degrades to honest empty independently, so
 * "nothing relevant" is a normal answer with exit 0, never a failure. provider_bound
 * is structural — sensitive/secret content is excluded by construction.
 */
class AtlasContextPackCommand extends Command
{
    public const SCHEMA = 'atlas.aobg.context_pack_command.v1';

    protected $signature = 'atlas:context-pack
        {task : Free-text task/question that drives the fused recall}
        {--workspace= : Workspace path or id to scope the pack (defaults to the primary atlas-server)}
        {--cwd= : Caller working directory used to resolve workspace when --workspace is absent}
        {--budget= : Total char budget for the assembled pack (defaults to config atlas.aobg.budget_chars)}
        {--code-budget= : Code graph char sub-budget}
        {--memory-budget= : Memory char sub-budget}
        {--changed-file=* : Changed/touched file path to bias code recall}
        {--task-type= : Task type for feedback-aware context delivery policy}
        {--domain= : Domain for feedback-aware context delivery policy}
        {--flow-id= : Explicit flow id for feedback-aware context delivery policy}
        {--feedback-window-hours= : Feedback window in hours for context delivery policy}
        {--json : Output the pack as JSON instead of a rendered markdown brief}';

    protected $description = 'AOBG N1.F1: the unified provider-bound context-pack front door — fuses code-graph + AURG + semantic memory into one budgeted brief. Read-only, cost-free, fail-safe (exit 0).';

    public function handle(AtlasOpenBrainContextPackService $service): int
    {
        $task = (string) ($this->argument('task') ?? '');

        $opts = [];
        $workspace = $this->option('workspace');
        if (is_string($workspace) && trim($workspace) !== '') {
            $opts['workspace'] = trim($workspace);
        }
        $cwd = $this->option('cwd');
        if (! isset($opts['workspace']) && is_string($cwd) && trim($cwd) !== '') {
            $opts['cwd'] = trim($cwd);
        }
        foreach ([
            'budget' => 'budget',
            'code-budget' => 'code_budget',
            'memory-budget' => 'memory_budget',
            'feedback-window-hours' => 'feedback_window_hours',
        ] as $option => $key) {
            $value = $this->option($option);
            if (is_string($value) && is_numeric(trim($value))) {
                $opts[$key] = (int) max(0, (int) floor((float) trim($value)));
            }
        }
        foreach ([
            'task-type' => 'task_type',
            'domain' => 'domain',
            'flow-id' => 'flow_id',
        ] as $option => $key) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $opts[$key] = trim($value);
            }
        }
        $changedFiles = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) $this->option('changed-file'),
        )));
        if ($changedFiles !== []) {
            $opts['changed_files'] = $changedFiles;
        }

        $pack = $service->packFor($task, $opts);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $counts = (array) ($pack['counts'] ?? []);
        $this->info(sprintf(
            'atlas:context-pack  task="%s"  workspace=%s  provider-bound=yes  code=%d  paths=%d  memory=%d  ~%d/%d chars',
            $task,
            (string) ($pack['workspace'] ?? ''),
            (int) ($counts['code_graph'] ?? 0),
            (int) ($counts['reality_graph_paths'] ?? 0),
            (int) ($counts['memory'] ?? 0),
            (int) data_get($pack, 'budget.estimated_chars', 0),
            (int) data_get($pack, 'budget.total_chars', 0),
        ));

        $this->line('');
        $this->line((string) ($pack['markdown'] ?? ''));

        return self::SUCCESS;
    }
}

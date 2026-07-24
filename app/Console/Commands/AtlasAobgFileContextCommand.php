<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainFileContextService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AOBG N2.F1 — `atlas:aobg:file-context`: the ACTIVE brain's PostToolUse surface.
 *
 * Given a file the engine just touched, returns the brain's brain-delta ABOUT that
 * file — the decisions/missions (AURG cross-layer paths) touching its module, the
 * provider-safe memories that reference it, and its code-graph neighbors (who
 * consumes it) — so a PostToolUse hook can inject "this file is governed by
 * decision X; a prior mission here failed by Y; it is consumed by Z" the moment
 * Claude Code opens/edits the file. Built on {@see AtlasOpenBrainFileContextService}.
 *
 *     atlas:aobg:file-context app/Services/Ai/Foo.php
 *     atlas:aobg:file-context app/Services/Ai/Foo.php --workspace=/path/to/project --json
 *
 * Read-only, local DB only, ZERO provider spend. Fail-SAFE (house contract): the
 * service NEVER throws and each section degrades to honest empty independently, so
 * an unknown / out-of-workspace / brain-less file is a normal answer with exit 0,
 * never a failure. provider_bound is structural — sensitive/secret is excluded by
 * construction. Budget-bounded + fast: it runs on the operator's interactive hook
 * path, so it must never stall a session.
 */
class AtlasAobgFileContextCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.aobg.file_context_command.v1';

    protected $signature = 'atlas:aobg:file-context
        {path : The file path the engine just touched (absolute or workspace-relative)}
        {--workspace= : Workspace path or id to scope the delta (defaults to the primary atlas-server)}
        {--budget= : Total char budget for the assembled delta (defaults to config atlas.aobg.file_context.budget_chars)}
        {--json : Output the delta as JSON instead of a rendered markdown brief}';

    protected $description = 'AOBG N2.F1: the brain-delta about a file the engine just touched — decisions/missions/memories/code-neighbors that govern it. Provider-bound, read-only, cost-free, fail-safe (exit 0).';

    public function handle(AtlasOpenBrainFileContextService $service): int
    {
        $path = (string) ($this->argument('path') ?? '');

        $opts = [];
        $workspace = $this->option('workspace');
        if (is_string($workspace) && trim($workspace) !== '') {
            $opts['workspace'] = trim($workspace);
        }
        $budget = $this->option('budget');
        if (is_string($budget) && is_numeric(trim($budget))) {
            $opts['budget'] = (int) max(0, (int) floor((float) trim($budget)));
        }

        $delta = $service->contextFor($path, $opts);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($delta));

            return self::SUCCESS;
        }

        $counts = (array) ($delta['counts'] ?? []);
        $this->info(sprintf(
            'atlas:aobg:file-context  file=%s  workspace=%s  provider-bound=yes  has_context=%s  defined=%d  consumers=%d  paths=%d  memory=%d  ~%d/%d chars  %dms',
            (string) ($delta['path'] ?? ''),
            (string) ($delta['workspace'] ?? ''),
            YesNo::format($delta['has_context'] ?? false),
            (int) ($counts['defined_symbols'] ?? 0),
            (int) ($counts['consumers'] ?? 0),
            (int) ($counts['reality_graph_paths'] ?? 0),
            (int) ($counts['memory'] ?? 0),
            (int) data_get($delta, 'budget.estimated_chars', 0),
            (int) data_get($delta, 'budget.total_chars', 0),
            (int) ($delta['elapsed_ms'] ?? 0),
        ));

        $this->line('');
        $this->line((string) ($delta['markdown'] ?? ''));

        return self::SUCCESS;
    }
}

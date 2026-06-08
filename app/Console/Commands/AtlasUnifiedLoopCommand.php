<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasUnifiedLoopOrchestrator;
use Illuminate\Console\Command;

/**
 * atlas:loop:unified — the single entry point for the unified, propose-only evolution
 * loop. Runs the verifier-backed modes (dead-code, docs-structure) to their honest
 * ceiling, refreshes the flagged (fake-implemented) backlog, holdout-gates every winner,
 * and writes a visible utilization report. NEVER merges to main.
 *
 * Kill-switch: `touch storage/atlas/loop/unified/STOP`. Report: `atlas:loop:unified:report`.
 */
final class AtlasUnifiedLoopCommand extends Command
{
    protected $signature = 'atlas:loop:unified
        {--base-workspace= : Repo root to scan + grind against (default: CWD)}
        {--modes=deadcode,docs_structure : Comma list of auto-loop modes to run}
        {--code-roots= : Comma list of code roots to scan (default: app)}
        {--docs-roots= : Comma list of docs roots to scan (default: docs/engineering-knowledge-base)}
        {--provider= : Execution provider (default: config atlas.loop.default_provider or hermes_cli)}
        {--run-id= : Resume/write a specific unified run id}
        {--scenarios=2 : Candidate scenarios explored per task}
        {--max-per-cycle=6 : Max findings ground per sweep before re-checking stop/budget}
        {--max-seconds=0 : Wall-clock budget (0 = one clean sweep then stop)}
        {--idle-seconds=60 : When drained, seconds to idle before re-scanning (watch mode)}
        {--once : Run a single sweep and stop}
        {--json : Print the canonical JSON result}';

    protected $description = 'Run the UNIFIED propose-only evolution loop: dead-code + docs-structure modes, fake-implemented backlog, honesty-gated, one visible report. Never merges.';

    public function handle(AtlasUnifiedLoopOrchestrator $orchestrator): int
    {
        $repoRoot = trim((string) $this->option('base-workspace')) ?: (string) getcwd();
        $modes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('modes')))));
        $provider = trim((string) $this->option('provider')) ?: null;

        $this->info('Atlas Unified Loop — propose-only, never merges. Kill: touch '.storage_path('atlas/loop/unified/STOP'));

        $codeRoots = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('code-roots')))));
        $docsRoots = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('docs-roots')))));

        $result = $orchestrator->run($repoRoot, [
            'modes' => $modes,
            'provider' => $provider,
            'run_id' => trim((string) $this->option('run-id')) ?: null,
            'code_roots' => $codeRoots ?: null,
            'docs_roots' => $docsRoots ?: null,
            'scenarios_per_task' => (int) $this->option('scenarios'),
            'max_per_cycle' => (int) $this->option('max-per-cycle'),
            'max_seconds' => (int) $this->option('max-seconds'),
            'idle_seconds' => (int) $this->option('idle-seconds'),
            'once' => (bool) $this->option('once'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $u = $result['report']['utilization'];
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Unified Loop</>', (string) $result['run_id']);
        $this->components->twoColumnDetail('Merged to main (must be no)', $result['merged_to_main'] ? 'YES — INVARIANT BROKEN' : 'no');
        $this->components->twoColumnDetail('Stop reason', (string) $result['stop_reason']);
        $this->components->twoColumnDetail('Cycles', (string) $result['cycles']);
        $this->components->twoColumnDetail('Tasks attempted', (string) $u['tasks_attempted']);
        $this->components->twoColumnDetail('Certified-for-review', (string) $u['certified_for_review']);
        $this->components->twoColumnDetail('Gate-rejected (held)', (string) $u['gate_rejected']);
        $this->components->twoColumnDetail('Reconstruction failed', (string) ($u['reconstruction_failed'] ?? 0));
        $this->components->twoColumnDetail('No winner', (string) $u['no_winner']);
        $this->components->twoColumnDetail('Yield (aproveitamento)', (string) $u['yield']);
        $this->components->twoColumnDetail('Flagged backlog (fake-impl)', (string) ($result['report']['backlog']['flagged_docs'] ?? 0).' docs');
        $this->components->twoColumnDetail('Report', 'php artisan atlas:loop:unified:report --run='.$result['run_id']);

        return self::SUCCESS;
    }
}

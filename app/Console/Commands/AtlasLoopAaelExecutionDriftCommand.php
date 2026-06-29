<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionDriftAuditor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasAaelExecutionDriftAuditor::audit()} at the operator surface: reads a task and its
 * exploration record from JSON and emits the execution-drift facts — paths touched outside the plan, planned
 * paths never touched, skipped acceptance commands, and whether the objective's anchor file went untouched.
 *
 * Read-only + pure: a plan-vs-actual diff only. No provider/DB/mutation.
 */
final class AtlasLoopAaelExecutionDriftCommand extends Command
{
    protected $signature = 'atlas:loop:aael-execution-drift {--input=} {--json}';

    protected $description = 'Read-only AAEL execution-drift audit (planned vs actual paths + acceptance commands).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('aael-execution-drift requires --input=<path to a readable {task, exploration} JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded) || ! is_array($decoded['task'] ?? null) || ! is_array($decoded['exploration'] ?? null)) {
            return $this->refuse('input JSON must be an object with `task` and `exploration` objects');
        }

        $audit = app(AtlasAaelExecutionDriftAuditor::class)->audit($decoded['task'], $decoded['exploration']);

        $facts = ['schema' => 'atlas.loop.aael_execution_drift.v1'] + $audit;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('drift_detected: '.($facts['drift_detected'] ? 'yes' : 'no'));
            $this->line('extra: '.implode(',', $facts['extra_paths_outside_plan']).'  missing: '.implode(',', $facts['missing_planned_paths']).'  skipped: '.implode(',', $facts['acceptance_commands_skipped']));
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}

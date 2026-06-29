<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphCoverageDossier;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionTaskGraphCoverageDossier::export()} at the operator
 * surface: reads the self-construction coverage/planner facts from JSON and emits the task-graph coverage
 * dossier — status (ready|hold|blocked), the per-organ rollup, and blockers — as deterministic facts.
 *
 * Pure + read-only + facts-only (no scalar score). No provider/DB/mutation.
 */
final class AtlasLoopTaskGraphCoverageCommand extends Command
{
    protected $signature = 'atlas:loop:taskgraph-coverage {--facts=} {--json}';

    protected $description = 'Read-only task-graph coverage dossier (per-organ rollup + status) from coverage/planner facts.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('facts'));
        if ($raw === '') {
            return $this->refuse('taskgraph-coverage requires --facts=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        $facts = json_decode($raw, true);
        if (! is_array($facts)) {
            return $this->refuse('--facts must be a JSON object');
        }

        $dossier = app(AtlasSelfConstructionTaskGraphCoverageDossier::class)->export($facts);

        if ($this->option('json')) {
            $this->line((string) json_encode($dossier, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line($dossier['proof_summary']);
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

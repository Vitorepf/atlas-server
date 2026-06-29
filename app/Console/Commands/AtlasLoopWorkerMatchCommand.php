<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerAssignmentMatcher;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionWorkerAssignmentMatcher::match()} at the operator surface:
 * previews which workers are eligible for a task (capabilities, risk class, scope allowlist, evidence support,
 * readiness) and surfaces the chosen worker (eligible, deterministic worker_id order) as facts.
 *
 * Pure + read-only: it matches and reports; it dispatches nothing and mutates nothing.
 */
final class AtlasLoopWorkerMatchCommand extends Command
{
    protected $signature = 'atlas:loop:worker-match {--task=} {--workers=} {--json}';

    protected $description = 'Read-only worker-to-task assignment match preview (eligible/ineligible + chosen).';

    public function handle(): int
    {
        $task = $this->readJson('task');
        $workers = $this->readJson('workers');
        if ($task === null) {
            return $this->refuse('worker-match requires --task=<json object or path>');
        }
        if ($workers === null || ! array_is_list($workers)) {
            return $this->refuse('worker-match requires --workers=<JSON array of workers>');
        }

        $result = app(AtlasSelfConstructionWorkerAssignmentMatcher::class)->match($task, array_values($workers));

        $facts = [
            'chosen_worker' => $result['eligible'][0]['worker_id'] ?? null,
            'eligible_count' => count($result['eligible']),
        ] + $result;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('chosen_worker: '.($facts['chosen_worker'] ?? '(none)').'  eligible: '.$facts['eligible_count']);
        }

        return self::SUCCESS;
    }

    /** @return array<mixed>|null */
    private function readJson(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
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

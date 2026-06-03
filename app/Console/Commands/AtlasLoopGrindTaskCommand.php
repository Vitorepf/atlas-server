<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Console\Command;

/**
 * Atlas Evolution Loop — per-task GRIND WORKER.
 *
 * The single unit of parallelism: one durable task, one process, no fan-out of its
 * own. The parallel pool spawns one of these per task; it is also runnable standalone.
 * It claims the task with a lease (so parallel workers never double-process and a
 * crash is reclaimable), then hands it to the shared {@see AtlasLoopTaskGrinder} which
 * grinds it through the PROVEN engine and streams the propose-only result to the
 * durable ledger. It NEVER merges.
 *
 * Exit codes: 0 = settled (winner or honest no-winner); 2 = could not claim (lost
 * lease / someone else owns it / exhausted) — the supervisor reclaims, no double-count.
 */
final class AtlasLoopGrindTaskCommand extends Command
{
    protected $signature = 'atlas:loop:grind-task
        {--task-id= : The atlas_loop_tasks.id to grind}
        {--worker= : Worker token for lease ownership (default: a generated token)}
        {--scenarios= : Override candidate scenarios explored this task}
        {--lease-seconds= : Claim lease TTL (default: config atlas.loop.campaign.task_lease_seconds)}
        {--workspace-root= : Per-worker scenario-workspace root (default: system temp)}
        {--json : Print the canonical JSON result}';

    protected $description = 'Grind ONE durable Evolution Loop task through the real engine and persist its proposal (propose-only).';

    public function handle(AtlasLoopStore $store, AtlasLoopTaskGrinder $grinder): int
    {
        if (function_exists('posix_setsid')) {
            @posix_setsid(); // own process group so a kill reaps the whole provider subtree
        }

        $taskId = trim((string) ($this->option('task-id') ?: ''));
        $task = $taskId !== '' ? AtlasLoopTask::query()->find($taskId) : null;
        if (! $task instanceof AtlasLoopTask) {
            $this->error('Task not found: '.$taskId);

            return self::FAILURE;
        }

        $worker = trim((string) ($this->option('worker') ?: '')) ?: 'worker-'.bin2hex(random_bytes(4));
        $leaseSeconds = $this->intOption('lease-seconds') ?? (int) config('atlas.loop.campaign.task_lease_seconds', 1800);

        // Claim this specific task with a lease. If we already own a live lease (pool
        // pre-claim) the claim is re-stamped; if someone else owns it, we exit cleanly.
        $claimed = ($task->claimed_by === $worker && $task->lease_expires_at !== null && $task->lease_expires_at->isFuture())
            ? $task
            : $store->claimSpecific($task->id, $worker, $leaseSeconds);

        if (! $claimed instanceof AtlasLoopTask) {
            $this->warn('Could not claim task (lost lease / owned / exhausted): '.$taskId);

            return 2;
        }

        $result = $grinder->grind(
            $claimed,
            $worker,
            $this->intOption('scenarios'),
            trim((string) ($this->option('workspace-root') ?: '')),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['task_id' => $claimed->id, 'worker' => $worker, ...$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Task', (string) $claimed->id);
            $this->components->twoColumnDetail('Outcome', (string) $result['status']);
            $this->components->twoColumnDetail('Scenarios explored', (string) $result['scenarios_explored']);
            $this->components->twoColumnDetail('Proposal (certified-for-review)', ($result['proposals'] ?? 0) > 0 ? 'yes' : 'no');
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }
}

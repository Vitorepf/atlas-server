<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopRunPersister;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Throwable;

/**
 * The shared grind core — the single path from a CLAIMED durable task to a persisted,
 * propose-only result. Both the serial supervisor (in-process) and the parallel
 * worker subcommand (one process per task) call this, so there is exactly ONE grind
 * path, never two divergent ones.
 *
 * For one claimed task it: admits against the disk gate (backpressure, never crash),
 * materializes the durable snapshot into a fresh per-worker-namespaced workspace, runs
 * the PROVEN {@see AtlasEvolutionLoopRunner} (deep search + frozen judge + propose-only),
 * and streams the result to the durable ledger via {@see AtlasLoopRunPersister}. The
 * task MUST already be claimed by $workerId (the caller owns the lease).
 */
final class AtlasLoopTaskGrinder
{
    public function __construct(
        private readonly AtlasLoopWorkspaceMaterializer $materializer,
        private readonly AtlasEvolutionLoopRunner $runner,
        private readonly AtlasLoopRunPersister $persister,
        private readonly AtlasLoopStore $store,
        private readonly AtlasLoopResourceGate $gate,
    ) {}

    /**
     * @return array{status:string, has_winner:bool, proposals:int, scenarios_explored:int, elapsed_seconds:int, reason?:string}
     */
    public function grind(AtlasLoopTask $task, string $workerId, ?int $scenarios = null, string $workspaceRoot = '', ?int $timeBudgetSeconds = null): array
    {
        $started = microtime(true);
        $this->store->markRunning($task->id, $workerId);

        $tmpRoot = $workspaceRoot !== '' ? $workspaceRoot : sys_get_temp_dir();
        $admit = $this->gate->admitScenario(
            $tmpRoot,
            (int) config('atlas.loop.campaign.min_free_mb', 512),
            (int) config('atlas.loop.campaign.max_live_workspaces', 0),
        );
        if (! $admit['admit']) {
            // Backpressure: hand the claim back so the supervisor retries once disk frees.
            $this->store->releaseClaim($task->id, $workerId);

            return ['status' => 'backpressure', 'reason' => (string) $admit['reason'], 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 0, 'elapsed_seconds' => 0];
        }

        $cleanup = static function (): void {};
        try {
            [$explorerTask, $cleanup] = $this->materializer->materialize((string) $task->objective, (array) $task->payload);
            if ($workspaceRoot !== '') {
                $explorerTask['workspace_root'] = $workspaceRoot; // namespace + reapable scenario copies
            }
            if ($timeBudgetSeconds !== null && $timeBudgetSeconds > 0) {
                $explorerTask['search_time_budget_seconds'] = $timeBudgetSeconds; // never overrun the campaign deadline
            }

            $options = [];
            if ($scenarios !== null && $scenarios > 0) {
                $options['scenarios_per_task'] = $scenarios;
            }

            $result = $this->runner->run([$explorerTask], $options);
            $summary = $this->persister->persist($task, $workerId, $result);
            $cleanup();

            return [
                'status' => $summary['has_winner'] ? 'winner' : 'no_winner',
                'has_winner' => $summary['has_winner'],
                'proposals' => $summary['proposals'],
                'scenarios_explored' => $summary['scenarios_explored'],
                'elapsed_seconds' => (int) ceil(microtime(true) - $started),
            ];
        } catch (Throwable $e) {
            $cleanup();
            // Infra failure (not a no-winner) — mark failed, lease-checked; counters untouched.
            $this->store->completeTask($task->id, $workerId, ['error' => mb_substr($e->getMessage(), 0, 400)], false);

            return ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200), 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 0, 'elapsed_seconds' => (int) ceil(microtime(true) - $started)];
        }
    }
}

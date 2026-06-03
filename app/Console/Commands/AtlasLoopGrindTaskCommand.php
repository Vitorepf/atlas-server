<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Atlas Evolution Loop — per-task GRIND WORKER.
 *
 * The single unit the campaign supervisor spawns in parallel. It takes one durable
 * task, claims it with a lease (so parallel workers never double-process and a crash
 * is reclaimable), rebuilds its scoped workspace from the durable snapshot, grinds it
 * through the PROVEN runner+explorer (deep search, frozen judge, propose-only), and
 * writes the exploration + any certified-for-review proposal back to the durable
 * ledger. It NEVER merges. Designed to be crash-safe and idempotent enough that a
 * re-run after a worker death simply re-grinds and re-records.
 */
final class AtlasLoopGrindTaskCommand extends Command
{
    protected $signature = 'atlas:loop:grind-task
        {--task-id= : The atlas_loop_tasks.id to grind}
        {--worker= : Worker token for lease ownership (default: a generated token)}
        {--scenarios= : Override candidate scenarios explored this task}
        {--lease-seconds= : Claim lease TTL (default: config atlas.loop.campaign.task_lease_seconds)}
        {--json : Print the canonical JSON result}';

    protected $description = 'Grind ONE durable Evolution Loop task through the real engine and persist its proposal (propose-only).';

    public function handle(AtlasEvolutionLoopRunner $runner, AtlasLoopWorkspaceMaterializer $materializer): int
    {
        $taskId = trim((string) ($this->option('task-id') ?: ''));
        $task = $taskId !== '' ? AtlasLoopTask::query()->find($taskId) : null;
        if (! $task instanceof AtlasLoopTask) {
            $this->error('Task not found: '.$taskId);

            return self::FAILURE;
        }

        $campaign = $task->campaign;
        if (! $campaign instanceof AtlasLoopCampaign) {
            $this->error('Task has no campaign: '.$taskId);

            return self::FAILURE;
        }

        $worker = trim((string) ($this->option('worker') ?: '')) ?: 'worker-'.bin2hex(random_bytes(4));
        $leaseSeconds = $this->intOption('lease-seconds') ?? (int) config('atlas.loop.campaign.task_lease_seconds', 1800);

        // Claim with a lease. The supervisor usually pre-claims; claiming again here is
        // safe and makes the worker runnable standalone (and crash-reclaimable).
        $task->forceFill([
            'status' => AtlasLoopTask::STATUS_RUNNING,
            'claimed_by' => $worker,
            'claimed_at' => Carbon::now(),
            'lease_expires_at' => Carbon::now()->addSeconds(max(60, $leaseSeconds)),
            'attempts' => (int) $task->attempts + 1,
        ])->save();

        $cleanup = static function (): void {};
        try {
            [$explorerTask, $cleanup] = $materializer->materialize((string) $task->objective, (array) $task->payload);

            $options = [];
            $scenarios = $this->intOption('scenarios') ?? (int) data_get($campaign->config, 'scenarios_per_task', config('atlas.loop.scenarios_per_task', 3));
            if ($scenarios > 0) {
                $options['scenarios_per_task'] = $scenarios;
            }

            $result = $runner->run([$explorerTask], $options);
            $persisted = $this->persist($campaign, $task, $result);

            $cleanup();

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($persisted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->twoColumnDetail('Task', (string) $task->id);
                $this->components->twoColumnDetail('Outcome', (string) $persisted['task_status']);
                $this->components->twoColumnDetail('Scenarios explored', (string) $persisted['scenarios_explored']);
                $this->components->twoColumnDetail('Proposal (certified-for-review)', $persisted['proposal_created'] ? 'yes' : 'no');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $cleanup();
            $task->forceFill([
                'status' => AtlasLoopTask::STATUS_FAILED,
                'result' => ['error' => mb_substr($e->getMessage(), 0, 400)],
                'lease_expires_at' => null,
            ])->save();
            $this->error('Grind failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Persist the engine output to the durable ledger inside one transaction and
     * advance the campaign's rolling counters.
     *
     * @param  array<string,mixed>  $result  AtlasEvolutionLoopRunner::run output
     * @return array<string,mixed>
     */
    private function persist(AtlasLoopCampaign $campaign, AtlasLoopTask $task, array $result): array
    {
        $explorations = is_array($result['explorations'] ?? null) ? $result['explorations'] : [];
        $proposals = is_array($result['proposals'] ?? null) ? $result['proposals'] : [];
        $hasWinner = $proposals !== [];
        $scenariosExplored = 0;

        return DB::transaction(function () use ($campaign, $task, $explorations, $proposals, $hasWinner, &$scenariosExplored): array {
            $proposalCreated = false;

            foreach ($explorations as $exploration) {
                $explored = (int) ($exploration['scenarios_explored'] ?? 0);
                $scenariosExplored += $explored;
                AtlasLoopExploration::query()->create([
                    'campaign_id' => $campaign->id,
                    'task_id' => $task->id,
                    'schema_version' => 'atlas.loop.exploration.v1',
                    'objective' => (string) ($exploration['objective'] ?? $task->objective),
                    'provider' => (string) ($exploration['provider'] ?? '') ?: null,
                    'scenarios_explored' => $explored,
                    'scenarios_accepted' => (int) ($exploration['scenarios_accepted'] ?? 0),
                    'has_winner' => (bool) ($exploration['has_winner'] ?? false),
                    'rejected_reasons' => array_values((array) ($exploration['rejected_reasons'] ?? [])),
                ]);
            }

            foreach ($proposals as $proposal) {
                // The model's structural guard forces merged_to_main=false + certified status.
                AtlasLoopProposal::query()->create([
                    'campaign_id' => $campaign->id,
                    'task_id' => $task->id,
                    'schema_version' => 'atlas.loop.proposal.v1',
                    'objective' => (string) ($proposal['objective'] ?? $task->objective),
                    'provider' => (string) ($proposal['provider'] ?? '') ?: null,
                    'target_path' => (string) $task->target_path ?: null,
                    'diff_text' => (string) ($proposal['diff_text'] ?? ''),
                    'proposal_hash' => (string) ($proposal['proposal_hash'] ?? hash('sha256', (string) ($proposal['diff_text'] ?? '').$task->id)),
                    'metric' => $proposal['metric'] ?? null,
                    'acceptance_hash' => (string) ($proposal['acceptance_hash'] ?? '') ?: null,
                    'scenarios_explored' => (int) ($proposal['scenarios_explored'] ?? 0),
                    'scenarios_accepted' => (int) ($proposal['scenarios_accepted'] ?? 0),
                    'winning_scenario' => (string) ($proposal['winning_scenario'] ?? '') ?: null,
                ]);
                $proposalCreated = true;
            }

            $task->forceFill([
                'status' => $hasWinner ? AtlasLoopTask::STATUS_DONE : AtlasLoopTask::STATUS_DEFERRED,
                'lease_expires_at' => null,
                'result' => [
                    'has_winner' => $hasWinner,
                    'scenarios_explored' => $scenariosExplored,
                    'proposals' => count($proposals),
                ],
            ])->save();

            $campaign->increment('tasks_processed');
            if ($scenariosExplored > 0) {
                $campaign->increment('scenarios_explored', $scenariosExplored);
            }
            if ($proposalCreated) {
                $campaign->increment('proposals_count', count($proposals));
            }

            return [
                'schema_version' => 'atlas.loop.grind_result.v1',
                'task_id' => $task->id,
                'task_status' => $task->status,
                'scenarios_explored' => $scenariosExplored,
                'proposal_created' => $proposalCreated,
                'merged_to_main' => false,
            ];
        });
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }
}

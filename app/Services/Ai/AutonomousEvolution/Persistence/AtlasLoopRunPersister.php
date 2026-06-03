<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Persistence;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The streaming-persistence seam. Takes ONE {@see AtlasEvolutionLoopRunner::run()}
 * result for a single task and writes — in a SINGLE transaction — the exploration
 * row(s), any winner proposal row(s), the task completion, and the campaign's rolling
 * totals. Called the instant each task finishes, so a crash mid-batch loses at most
 * the in-flight task, never a finished proposal. Fail-closed: if the engine ever
 * reports merged_to_main=true the whole persist aborts (the propose-only invariant's
 * runtime assertion, on top of the model + DB guards).
 */
final class AtlasLoopRunPersister
{
    public function __construct(
        private readonly AtlasLoopStore $store,
    ) {}

    public function assertNeverMerged(array $engineResult): void
    {
        if (($engineResult['merged_to_main'] ?? false) === true) {
            throw new RuntimeException('propose-only invariant violated: engine result reported merged_to_main=true');
        }
    }

    /**
     * @param  array<string,mixed>  $runnerResult  AtlasEvolutionLoopRunner::run output
     * @return array{has_winner:bool, proposals:int, scenarios_explored:int, exploration_ids:list<string>, proposal_ids:list<string>}
     */
    public function persist(AtlasLoopTask $task, string $workerId, array $runnerResult): array
    {
        $this->assertNeverMerged($runnerResult);

        $explorations = is_array($runnerResult['explorations'] ?? null) ? $runnerResult['explorations'] : [];
        $proposals = is_array($runnerResult['proposals'] ?? null) ? $runnerResult['proposals'] : [];
        $hasWinner = $proposals !== [];

        return DB::transaction(function () use ($task, $workerId, $explorations, $proposals, $hasWinner): array {
            $scenariosExplored = 0;
            $explorationIds = [];
            $proposalIds = [];

            foreach ($explorations as $exploration) {
                $scenariosExplored += (int) ($exploration['scenarios_explored'] ?? 0);
                $explorationIds[] = $this->store->recordExploration($task, is_array($exploration) ? $exploration : [])->id;
            }

            foreach ($proposals as $proposal) {
                $proposalIds[] = $this->store->certifyProposal($task, is_array($proposal) ? $proposal : [])->id;
            }

            // A processed task is DONE whether or not it yielded a winner — a no-winner
            // is an honest "explored, nothing better found", not a failure. Infra errors
            // are marked failed by the caller's catch, never here.
            $this->store->completeTask($task->id, $workerId, [
                'has_winner' => $hasWinner,
                'scenarios_explored' => $scenariosExplored,
                'proposals' => count($proposals),
            ], true);

            $campaign = AtlasLoopCampaign::query()->whereKey($task->campaign_id)->first();
            if ($campaign instanceof AtlasLoopCampaign) {
                $campaign->increment('tasks_processed');
                if ($scenariosExplored > 0) {
                    $campaign->increment('scenarios_explored', $scenariosExplored);
                }
                if ($proposalIds !== []) {
                    $campaign->increment('proposals_count', count($proposalIds));
                }
            }

            return [
                'has_winner' => $hasWinner,
                'proposals' => count($proposalIds),
                'scenarios_explored' => $scenariosExplored,
                'exploration_ids' => $explorationIds,
                'proposal_ids' => $proposalIds,
            ];
        });
    }
}

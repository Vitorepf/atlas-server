<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilLeverageRanker;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilRoadmapCandidateFilter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeSignalProjector;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only strategy-loop runtime. Pipes roadmap candidates through the
 * Strategy Council pipeline, in order:
 *
 *   1. {@see AtlasStrategyCouncilRoadmapCandidateFilter} — drops resolved,
 *      quarantined, poisoned, stale, duplicate, out-of-scope or proxy-kind
 *      candidates before anything competes.
 *   2. {@see AtlasStrategyCouncilLeverageRanker} — orders survivors by real
 *      leverage evidence (never task volume or hype); proxy-signal-only
 *      candidates with no real lever are rejected here too.
 *   3. {@see AtlasStrategyCouncilAmbitionBudgetPolicy} — decides the ambition
 *      level (hold/narrow/standard/bold) for the winning candidate given
 *      risk, budget, autonomy mode and dependency readiness.
 *   4. {@see AtlasStrategyCouncilDecisionLedger} — persists the durable
 *      decision (selected + rejected alternatives + ambition + evidence)
 *      ONLY when a candidate wins AND ambition is not "hold" — proposal
 *      competition is evidence-bound, not theme churn or a rubber stamp.
 *
 * Never enqueues, mutates the queue, or calls a provider.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { candidates:list, admitted_owner_scopes?:list<string>, worker_floor_low?:bool,
 *     ranker_context?:{claimable_per_active_worker?:float}, ambition_facts:array,
 *     decision_id?:string, decided_at?:string, ledger_path?:string }
 */
final class AtlasExternalBrainStrategyLoopCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:strategy-loop
        {--input= : Path to a JSON file with candidates, ambition_facts, and optional ledger_path}';

    /** @var string */
    protected $description = 'Read-only strategy-loop runtime: filter → leverage-rank → ambition-budget → durable decision ledger.';

    public function handle(
        AtlasStrategyCouncilRoadmapCandidateFilter $filter,
        AtlasStrategyCouncilLeverageRanker $ranker,
        AtlasStrategyCouncilAmbitionBudgetPolicy $ambitionPolicy,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $candidates = is_array($decoded['candidates'] ?? null) ? $decoded['candidates'] : [];
        $outcomeMemory = is_array($decoded['outcomes'] ?? null)
            ? $decoded['outcomes']
            : (is_array($decoded['outcome_memory'] ?? null) ? $decoded['outcome_memory'] : []);
        $outcomeProjection = (new AtlasExternalBrainOutcomeSignalProjector)->project(
            $candidates,
            $outcomeMemory,
            is_array($decoded['recurrence_map'] ?? null) ? $decoded['recurrence_map'] : [],
        );
        $candidates = $outcomeProjection['candidates'];
        $scopes = is_array($decoded['admitted_owner_scopes'] ?? null) ? array_map('strval', $decoded['admitted_owner_scopes']) : [];
        $workerFloorLow = (bool) ($decoded['worker_floor_low'] ?? false);
        $rankerContext = is_array($decoded['ranker_context'] ?? null) ? $decoded['ranker_context'] : [];
        $ambitionFacts = is_array($decoded['ambition_facts'] ?? null) ? $decoded['ambition_facts'] : [];

        $filtered = $filter->filter($candidates, $scopes, $workerFloorLow);

        $keptByCandidateId = [];
        foreach ($filtered['kept'] as $c) {
            $keptByCandidateId[(string) ($c['candidate_id'] ?? '')] = $c;
        }

        $ranked = $ranker->rank($filtered['kept'], $rankerContext);
        $ambition = $ambitionPolicy->decide($ambitionFacts);

        $winner = $ranked['ranked'][0] ?? null;
        $decisionResult = null;
        $decisionSkippedReason = null;

        if ($winner === null) {
            $decisionSkippedReason = 'no_ranked_candidate_survived_filter_and_rank';
        } elseif ($ambition['ambition_level'] === AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD) {
            $decisionSkippedReason = 'ambition_level_hold';
        } else {
            $winnerId = (string) $winner['candidate_id'];
            $winnerCandidate = $keptByCandidateId[$winnerId] ?? [];
            $rejectedIds = array_values(array_filter(array_merge(
                array_column(array_slice($ranked['ranked'], 1), 'candidate_id'),
                array_column($ranked['rejected'], 'candidate_id'),
            ), static fn (string $id): bool => $id !== ''));

            $evidenceRefs = array_values((array) ($winnerCandidate['evidence_refs'] ?? []));
            $decisionId = (string) ($decoded['decision_id'] ?? ('strategy-loop-'.$winnerId));
            $decidedAt = (string) ($decoded['decided_at'] ?? '');

            $ledgerPath = (string) ($decoded['ledger_path'] ?? '');
            if ($evidenceRefs === [] || $decidedAt === '' || $ledgerPath === '') {
                $decisionSkippedReason = 'missing_required_ledger_fields:evidence_refs_or_decided_at_or_ledger_path';
            } else {
                try {
                    $decisionResult = (new AtlasStrategyCouncilDecisionLedger($ledgerPath))->append([
                        'decision_id' => $decisionId,
                        'selected_candidate_id' => $winnerId,
                        'rejected_candidate_ids' => $rejectedIds,
                        'reason_vectors' => $winner['reasons'],
                        'ambition_level' => $ambition['ambition_level'],
                        'evidence_refs' => $evidenceRefs,
                        'decided_at' => $decidedAt,
                        'supply_state' => is_array($decoded['supply_state'] ?? null) ? $decoded['supply_state'] : [],
                        'selected_layer' => (string) ($decoded['selected_layer'] ?? ''),
                        'rejected_alternatives' => is_array($decoded['rejected_alternatives'] ?? null) ? $decoded['rejected_alternatives'] : [],
                        'outcome_learning_ref' => (string) ($decoded['outcome_learning_ref'] ?? ''),
                    ]);
                } catch (Throwable $e) {
                    $decisionSkippedReason = 'ledger_append_failed:'.$e->getMessage();
                }
            }
        }

        $payload = [
            'status' => 'ok',
            'filtered' => ['kept_count' => count($filtered['kept']), 'dropped' => $filtered['dropped']],
            'ranked' => $ranked['ranked'],
            'rejected_by_ranker' => $ranked['rejected'],
            'ambition' => $ambition,
            'outcome_projection' => [
                'schema' => $outcomeProjection['schema'],
                'signals' => $outcomeProjection['signals'],
            ],
            'decision' => $decisionResult,
            'decision_skipped_reason' => $decisionSkippedReason,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}

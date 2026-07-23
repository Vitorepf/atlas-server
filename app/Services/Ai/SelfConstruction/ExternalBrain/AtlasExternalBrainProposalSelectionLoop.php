<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Promotes {@see AtlasExternalBrainProposalArena} from a read-only command
 * surface into the real originator choice point: candidate batches compete
 * through the arena, rejected reasons (disqualification or being outscored)
 * are preserved verbatim, and ONLY the arena's winner — already past every
 * proxy/duplicate/implementability/template-farm disqualifier — is handed to
 * {@see AtlasExternalBrainHighValueBatchComposer}. A losing or disqualified
 * candidate never reaches the composer path.
 *
 * Pure / deterministic: no I/O, no side effects.
 */
final class AtlasExternalBrainProposalSelectionLoop
{
    public const SCHEMA = 'atlas.external_brain.proposal_selection_loop.v1';

    public const ESCALATION_RESEARCH_TO_TASK = 'research_to_task';

    public const ESCALATION_SIMPLIFICATION_FIRST = 'simplification_first';

    public const ESCALATION_SECOND_PASS_CANDIDATE_SEARCH = 'second_pass_candidate_search';

    public function __construct(
        private readonly AtlasExternalBrainProposalArena $arena = new AtlasExternalBrainProposalArena(),
        private readonly AtlasExternalBrainHighValueBatchComposer $composer = new AtlasExternalBrainHighValueBatchComposer(),
    ) {
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>        $composeOptions  {max_batch?: int}
     * @return array{
     *     schema: string,
     *     verdict: string,
     *     winner: array<string,mixed>|null,
     *     rejected: list<array<string,mixed>>,
     *     arena_hash: string,
     *     batch: array<string,mixed>|null,
     * }
     */
    public function select(array $candidates, array $composeOptions = []): array
    {
        $arenaResult = $this->arena->compete([
            'proposals' => $candidates,
            'require_competition' => (bool) ($composeOptions['require_competition'] ?? ($this->isAutonomosMode($composeOptions))),
            'require_quality_contract' => (bool) ($composeOptions['require_quality_contract'] ?? ($this->isAutonomosMode($composeOptions))),
        ]);

        $batch = null;
        if ($arenaResult['verdict'] === AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED) {
            $batch = $this->composer->compose([$arenaResult['winner']], $composeOptions);
        }

        $rejectedDossier = array_map(static function (array $r): array {
            return [
                'proposal_id' => $r['proposal_id'],
                'reason'      => $r['reason'],
                'source'      => $r['reason'] === 'outscored_by_winner' ? 'outscored' : 'disqualification',
            ];
        }, $arenaResult['rejected']);

        // A no-winner arena result must never read as "stop" — it must hand the originator concrete
        // next moves: go research the gap, retry with a simpler/smaller candidate, or run a fresh
        // candidate-search pass instead of reusing the same rejected batch.
        $escalationDossier = null;
        if ($arenaResult['verdict'] === AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED) {
            $escalationDossier = [
                'reason' => 'no_winner_all_candidates_rejected',
                'actions' => [
                    self::ESCALATION_RESEARCH_TO_TASK,
                    self::ESCALATION_SIMPLIFICATION_FIRST,
                    self::ESCALATION_SECOND_PASS_CANDIDATE_SEARCH,
                ],
                'rejected_dossier' => $rejectedDossier,
            ];
        }

        // AC1: group rejected proposals by disqualification class.
        $rejectedByClass = [];
        foreach ($arenaResult['rejected'] as $r) {
            $class = $r['reason'] === 'outscored_by_winner' ? 'outscored' : $r['reason'];
            $rejectedByClass[$class][] = $r['proposal_id'];
        }

        // AC2: derive next-batch avoid patterns from rejection reasons.
        $nextBatchAvoidPatterns = [];
        foreach ($arenaResult['rejected'] as $r) {
            $pattern = match ($r['reason']) {
                AtlasExternalBrainProposalArena::DISQUALIFY_PROXY            => 'avoid_proxy_heavy_proposals',
                AtlasExternalBrainProposalArena::DISQUALIFY_DUPLICATE        => 'avoid_duplicate_proposals',
                AtlasExternalBrainProposalArena::DISQUALIFY_UNIMPLEMENTABLE  => 'raise_implementability_floor',
                AtlasExternalBrainProposalArena::DISQUALIFY_NO_EVIDENCE_PATH => 'require_runnable_evidence_path',
                AtlasExternalBrainProposalArena::DISQUALIFY_EVIDENCE_QUORUM  => 'ensure_independent_evidence_quorum',
                AtlasExternalBrainProposalArena::DISQUALIFY_QUALITY_CONTRACT => 'supply_finding_baseline_delta_rollback_and_test',
                AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM    => 'avoid_template_farm_patterns',
                AtlasExternalBrainProposalArena::DISQUALIFY_COMPETITION_QUORUM => 'generate_independent_competing_proposals',
                default                                                     => null,
            };
            if ($pattern !== null) {
                $nextBatchAvoidPatterns[$pattern] = true;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => $arenaResult['verdict'],
            'winner' => $arenaResult['winner'],
            'rejected' => $arenaResult['rejected'],
            'rejected_dossier' => $rejectedDossier,
            'rejected_by_class' => $rejectedByClass,
            'next_batch_avoid_patterns' => array_keys($nextBatchAvoidPatterns),
            'escalation_dossier' => $escalationDossier,
            'arena_hash' => $arenaResult['arena_hash'],
            'batch' => $batch,
        ];
    }

    private function isAutonomosMode(array $composeOptions): bool
    {
        return strtolower(trim((string) ($composeOptions['autonomy_mode'] ?? ''))) === 'autonomos';
    }
}

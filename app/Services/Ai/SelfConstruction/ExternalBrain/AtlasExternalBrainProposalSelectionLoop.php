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
        $arenaResult = $this->arena->compete(['proposals' => $candidates]);

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

        return [
            'schema' => self::SCHEMA,
            'verdict' => $arenaResult['verdict'],
            'winner' => $arenaResult['winner'],
            'rejected' => $arenaResult['rejected'],
            'rejected_dossier' => $rejectedDossier,
            'arena_hash' => $arenaResult['arena_hash'],
            'batch' => $batch,
        ];
    }
}

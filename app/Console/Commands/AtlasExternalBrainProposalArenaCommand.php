<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalArena;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalReplayCourt;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only proposal arena runner. Pipes candidate proposals through
 * {@see AtlasExternalBrainProposalReplayCourt} (duplicate/scaffold/task-fabric/
 * evidence gates) and then {@see AtlasExternalBrainProposalArena} (proxy,
 * duplicate-flag, unimplementable and template-farm disqualifiers plus
 * scoring) so only a single, well-formed winner ever surfaces — before any
 * enqueue step. Never enqueues, mutates the queue, or calls a provider.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { proposals:list, existing_queue_targets?:list, min_scaffold_score?:float,
 *     require_evidence?:bool, max_blast_radius?:float }
 * Each proposal item may carry either (or both) 'id' and 'proposal_id' — they
 * are normalized to the same value before either stage runs.
 */
final class AtlasExternalBrainProposalArenaCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:proposal-arena
        {--input= : Path to a JSON file with proposals, existing_queue_targets, min_scaffold_score, require_evidence, max_blast_radius}';

    /** @var string */
    protected $description = 'Read-only proposal replay-court + arena runner: returns the single winner with loser reasons before any enqueue step.';

    public function handle(AtlasExternalBrainProposalArena $arena, AtlasExternalBrainProposalReplayCourt $court): int
    {
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

        $rawProposals = is_array($decoded['proposals'] ?? null) ? $decoded['proposals'] : [];
        $proposals = [];
        foreach ($rawProposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $id = (string) ($proposal['id'] ?? $proposal['proposal_id'] ?? '');
            $proposal['id'] = $id;
            $proposal['proposal_id'] = $id;
            $proposals[] = $proposal;
        }

        $courtFacts = ['proposals' => $proposals];
        foreach (['existing_queue_targets', 'min_scaffold_score', 'require_evidence', 'max_blast_radius'] as $key) {
            if (array_key_exists($key, $decoded)) {
                $courtFacts[$key] = $decoded[$key];
            }
        }

        $courtVerdict = $court->adjudicate($courtFacts);
        $courtAcceptedIds = $courtVerdict['accepted_proposals'];

        $arenaCandidates = array_values(array_filter(
            $proposals,
            static fn (array $p): bool => in_array($p['id'], $courtAcceptedIds, true),
        ));

        $arenaVerdict = $arena->compete(['proposals' => $arenaCandidates]);

        $rejected = [];
        foreach ($courtVerdict['rejected_proposals'] as $r) {
            $rejected[] = [
                'proposal_id' => $r['id'],
                'stage' => 'replay_court',
                'reasons' => $r['rejection_reasons'],
            ];
        }
        foreach ($arenaVerdict['rejected'] as $r) {
            $rejected[] = [
                'proposal_id' => $r['proposal_id'],
                'stage' => 'arena',
                'reasons' => [$r['reason']],
            ];
        }

        $payload = [
            'status' => 'ok',
            'verdict' => $arenaVerdict['verdict'],
            'winner' => $arenaVerdict['winner'],
            'rejected' => $rejected,
            'court_verdict' => $courtVerdict['court_verdict'],
            'arena_hash' => $arenaVerdict['arena_hash'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}

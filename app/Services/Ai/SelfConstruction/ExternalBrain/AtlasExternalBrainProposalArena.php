<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure arena. Multiple candidate evolution proposals compete; only the strongest
 * becomes a task recommendation.
 *
 * Scoring dimensions:
 *   +0.30  leverage              — structural Atlas capability unlocked
 *   +0.25  evidence_strength     — strength of evidence that this is feasible
 *   +0.20  implementability      — can a worker act on this without guesses?
 *   +0.15  compression_opportunity — reduces codebase complexity or duplication
 *   +0.10  operator_rank_priority — operator signals this family matters now
 *   −0.20  anti_goodhart_risk    — penalty: high risk = proxy/metric-gaming
 *   −0.15  historical_give_back_rate — penalty: family with bad muscle outcomes
 *   −0.10  quarantine_rate       — penalty: family under active quarantine
 *   −0.05  queue_pressure        — penalty: similar tasks already in queue
 *
 * Disqualifiers (first match wins; proposal excluded before scoring):
 *   is_proxy=true                  → 'proxy_heavy'
 *   is_duplicate=true              → 'duplicate'
 *   implementability < 0.30        → 'unimplementable'
 *   has_runnable_evidence_path=false → 'no_runnable_evidence_path'
 *   template_similarity >= 0.70 AND repeated_pattern_count >= 3 → 'template_farm'
 *
 * If every proposal is disqualified → verdict='all_rejected'.
 * Tie-break: proposal_id lexicographic ascending (deterministic).
 *
 * operator_rank_priority breaks close calls without overriding implementability
 * or evidence — it contributes +0.10 to score, never overrides disqualifiers.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProposalArena
{
    public const SCHEMA = 'atlas.external_brain.proposal_arena.v1';

    public const VERDICT_WINNER_SELECTED = 'winner_selected';
    public const VERDICT_ALL_REJECTED    = 'all_rejected';

    public const DISQUALIFY_PROXY              = 'proxy_heavy';
    public const DISQUALIFY_DUPLICATE          = 'duplicate';
    public const DISQUALIFY_UNIMPLEMENTABLE    = 'unimplementable';
    public const DISQUALIFY_NO_EVIDENCE_PATH   = 'no_runnable_evidence_path';
    public const DISQUALIFY_TEMPLATE_FARM      = 'template_farm';

    private const IMPLEMENTABILITY_FLOOR    = 0.30;
    private const TEMPLATE_FARM_SIMILARITY  = 0.70;
    private const TEMPLATE_FARM_REPEAT      = 3;

    private const WEIGHTS = [
        'leverage'                  =>  0.30,
        'evidence_strength'         =>  0.25,
        'implementability'          =>  0.20,
        'compression_opportunity'   =>  0.15,
        'operator_rank_priority'    =>  0.10,
        'anti_goodhart_risk'        => -0.20,
        'historical_give_back_rate' => -0.15,
        'quarantine_rate'           => -0.10,
        'queue_pressure'            => -0.05,
    ];

    /**
     * @param  array{proposals?: list<array<string,mixed>>}  $input
     * @return array{schema:string, verdict:string, winner:array<string,mixed>|null,
     *               rejected:list<array<string,mixed>>, arena_hash:string}
     */
    public function compete(array $input): array
    {
        $proposals = is_array($input['proposals'] ?? null) ? $input['proposals'] : [];

        $scored   = [];
        $rejected = [];

        foreach ($proposals as $proposal) {
            $id = (string) ($proposal['proposal_id'] ?? '');

            $disqualifyReason = $this->disqualifyReason($proposal);
            if ($disqualifyReason !== null) {
                $rejected[] = ['proposal_id' => $id, 'reason' => $disqualifyReason];

                continue;
            }

            $scored[] = [
                'proposal'    => $proposal,
                'arena_score' => $this->computeScore($proposal),
                'proposal_id' => $id,
            ];
        }

        // Sort: score desc, then proposal_id asc (deterministic tie-break).
        usort($scored, static function (array $a, array $b): int {
            $diff = $b['arena_score'] <=> $a['arena_score'];

            return $diff !== 0 ? $diff : strcmp($a['proposal_id'], $b['proposal_id']);
        });

        if ($scored === []) {
            return [
                'schema'     => self::SCHEMA,
                'verdict'    => self::VERDICT_ALL_REJECTED,
                'winner'     => null,
                'rejected'   => $rejected,
                'arena_hash' => $this->hash(null, $rejected),
            ];
        }

        $winnerEntry = $scored[0];
        foreach (array_slice($scored, 1) as $entry) {
            $rejected[] = ['proposal_id' => $entry['proposal_id'], 'reason' => 'outscored_by_winner'];
        }

        $winner = array_merge($winnerEntry['proposal'], ['arena_score' => round($winnerEntry['arena_score'], 4)]);

        return [
            'schema'     => self::SCHEMA,
            'verdict'    => self::VERDICT_WINNER_SELECTED,
            'winner'     => $winner,
            'rejected'   => $rejected,
            'arena_hash' => $this->hash($winner, $rejected),
        ];
    }

    private function disqualifyReason(array $proposal): ?string
    {
        if ((bool) ($proposal['is_proxy'] ?? false)) {
            return self::DISQUALIFY_PROXY;
        }
        if ((bool) ($proposal['is_duplicate'] ?? false)) {
            return self::DISQUALIFY_DUPLICATE;
        }
        if ((float) ($proposal['implementability'] ?? 1.0) < self::IMPLEMENTABILITY_FLOOR) {
            return self::DISQUALIFY_UNIMPLEMENTABLE;
        }
        if (array_key_exists('has_runnable_evidence_path', $proposal) && ! (bool) $proposal['has_runnable_evidence_path']) {
            return self::DISQUALIFY_NO_EVIDENCE_PATH;
        }
        if ((float) ($proposal['template_similarity'] ?? 0.0) >= self::TEMPLATE_FARM_SIMILARITY
            && (int) ($proposal['repeated_pattern_count'] ?? 0) >= self::TEMPLATE_FARM_REPEAT) {
            return self::DISQUALIFY_TEMPLATE_FARM;
        }

        return null;
    }

    private function computeScore(array $proposal): float
    {
        $score = 0.0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $value = max(0.0, min(1.0, (float) ($proposal[$dim] ?? 0.0)));
            $score += $weight * $value;
        }

        return $score;
    }

    private function hash(?array $winner, array $rejected): string
    {
        $payload = json_encode(
            ['winner_id' => $winner['proposal_id'] ?? null, 'rejected_count' => count($rejected)],
            JSON_UNESCAPED_SLASHES,
        );

        return 'arena_' . substr(hash('sha256', (string) $payload), 0, 24);
    }
}

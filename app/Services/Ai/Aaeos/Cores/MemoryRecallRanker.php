<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

final class MemoryRecallRanker
{
    private const SCHEMA_VERSION = 'atlas.aaeos.memory_recall_ranking.v1';

    /**
     * Scope precedence (rule 1): task=0 < project=1 < session=2 < global=3 < other=4.
     */
    private const SCOPE_RANK = [
        'task' => 0,
        'project' => 1,
        'session' => 2,
        'global' => 3,
    ];

    private const SCOPE_RANK_OTHER = 4;

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array{
     *     schema_version:string,
     *     ranked:list<array<string,mixed>>,
     *     deduplicated:list<array{id:string,kept_id:string,dedup_key:string}>
     * }
     */
    public function rank(array $candidates): array
    {
        $ordered = array_values($candidates);

        usort($ordered, fn (array $a, array $b): int => $this->compare($a, $b));

        $ranked = [];
        $deduplicated = [];
        $keptByDedupKey = [];

        foreach ($ordered as $candidate) {
            $dedupKey = $this->dedupKey($candidate);

            if (isset($keptByDedupKey[$dedupKey])) {
                $deduplicated[] = [
                    'id' => $this->stringField($candidate, 'id'),
                    'kept_id' => $keptByDedupKey[$dedupKey],
                    'dedup_key' => $dedupKey,
                ];

                continue;
            }

            $keptByDedupKey[$dedupKey] = $this->stringField($candidate, 'id');
            $ranked[] = $candidate;
        }

        $rankedOut = [];
        $rankPosition = 0;

        foreach ($ranked as $candidate) {
            $rankPosition++;
            $rankedOut[] = $this->decorate($candidate, $rankPosition);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ranked' => $rankedOut,
            'deduplicated' => $deduplicated,
        ];
    }

    /**
     * Pairwise total-order comparator over the 6 ranking rules.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public function compare(array $a, array $b): int
    {
        $keyA = $this->orderKey($a);
        $keyB = $this->orderKey($b);

        if ($keyA['scope_rank'] !== $keyB['scope_rank']) {
            return $keyA['scope_rank'] <=> $keyB['scope_rank'];
        }

        if ($keyA['decision_rank'] !== $keyB['decision_rank']) {
            return $keyA['decision_rank'] <=> $keyB['decision_rank'];
        }

        if ($keyA['canonical_rank'] !== $keyB['canonical_rank']) {
            return $keyA['canonical_rank'] <=> $keyB['canonical_rank'];
        }

        if ($keyA['unresolved_rank'] !== $keyB['unresolved_rank']) {
            return $keyA['unresolved_rank'] <=> $keyB['unresolved_rank'];
        }

        if ($keyA['score'] !== $keyB['score']) {
            return $keyB['score'] <=> $keyA['score'];
        }

        return strcmp($keyA['tiebreak'], $keyB['tiebreak']);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{
     *     scope_rank:int,
     *     decision_rank:int,
     *     canonical_rank:int,
     *     unresolved_rank:int,
     *     score:float,
     *     tiebreak:string
     * }
     */
    private function orderKey(array $candidate): array
    {
        return [
            'scope_rank' => $this->scopeRank($candidate),
            'decision_rank' => $this->decisionRank($candidate),
            'canonical_rank' => $this->canonicalRank($candidate),
            'unresolved_rank' => $this->unresolvedRank($candidate),
            'score' => $this->score($candidate),
            'tiebreak' => $this->tiebreak($candidate),
        ];
    }

    /**
     * Rule 1: explicit task/project/session scope over global memory.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function scopeRank(array $candidate): int
    {
        $scope = $this->stringField($candidate, 'scope');

        return self::SCOPE_RANK[$scope] ?? self::SCOPE_RANK_OTHER;
    }

    /**
     * Rule 2: accepted decisions over observations/non-accepted.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function decisionRank(array $candidate): int
    {
        $isAcceptedDecision = $this->stringField($candidate, 'kind') === 'decision'
            && $this->stringField($candidate, 'decision_status') === 'accepted';

        return $isAcceptedDecision ? 0 : 1;
    }

    /**
     * Rule 4: docs with canonical status over archived/source material.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function canonicalRank(array $candidate): int
    {
        $docStatus = $this->stringField($candidate, 'doc_status');

        if ($docStatus === 'canonical') {
            return 0;
        }

        if ($docStatus === 'archived' || $docStatus === 'source') {
            return 1;
        }

        return 2;
    }

    /**
     * Rule 3: recent unresolved issues only when relevant to the task.
     * Task-relevant unresolved issues rank ahead of non-task-relevant unresolved.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function unresolvedRank(array $candidate): int
    {
        $unresolved = $this->boolField($candidate, 'unresolved_issue');

        if (! $unresolved) {
            return 1;
        }

        return $this->boolField($candidate, 'task_relevant') ? 0 : 1;
    }

    /**
     * Rule 5 input: higher semantic_score wins (handled in compare()).
     *
     * @param  array<string,mixed>  $candidate
     */
    private function score(array $candidate): float
    {
        $value = $candidate['semantic_score'] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Final tie-break: ascending strcmp of sha1 over id/source_ref.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function tiebreak(array $candidate): string
    {
        $id = $this->stringField($candidate, 'id');
        $sourceRef = $this->stringField($candidate, 'source_ref');

        return sha1($id.'/'.$sourceRef);
    }

    /**
     * Rule 5 dedup: by semantic role (kind) and source path/id.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function dedupKey(array $candidate): string
    {
        return $this->stringField($candidate, 'kind').'|'.$this->stringField($candidate, 'source_ref');
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function decorate(array $candidate, int $rank): array
    {
        $key = $this->orderKey($candidate);

        $candidate['rank'] = $rank;
        $candidate['reason'] = $this->reason($candidate, $key);
        $candidate['order_key'] = $key;

        return $candidate;
    }

    /**
     * Rule 6: include reason strings so an agent can explain the injection.
     *
     * @param  array<string,mixed>  $candidate
     * @param  array{scope_rank:int,decision_rank:int,canonical_rank:int,unresolved_rank:int,score:float,tiebreak:string}  $key
     */
    private function reason(array $candidate, array $key): string
    {
        $parts = ['scope='.$this->stringField($candidate, 'scope').'(#'.$key['scope_rank'].')'];

        if ($key['decision_rank'] === 0) {
            $parts[] = 'accepted_decision';
        }

        if ($key['canonical_rank'] === 0) {
            $parts[] = 'canonical';
        } elseif ($key['canonical_rank'] === 1) {
            $parts[] = 'archived_or_source';
        }

        if ($key['unresolved_rank'] === 0) {
            $parts[] = 'task_relevant_unresolved';
        }

        $parts[] = 'score='.$this->formatScore($key['score']);

        return implode('; ', $parts);
    }

    private function formatScore(float $score): string
    {
        if ($score === (float) (int) $score) {
            return (string) (int) $score;
        }

        return rtrim(rtrim(sprintf('%.6f', $score), '0'), '.');
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function stringField(array $candidate, string $key): string
    {
        $value = $candidate[$key] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function boolField(array $candidate, string $key): bool
    {
        return ($candidate[$key] ?? false) === true;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AtlasOpenBrainWriteBackService;

/**
 * C3 (Obra #18) — symmetric closure. Today only the Claude Code session (Stop hook)
 * returns learning to the registry; the esteira's report() closes to a silo. This
 * derives a G0 memory candidate from a task OUTCOME so the esteira feeds the SAME
 * governed channel ({@see AtlasOpenBrainWriteBackService::proposeLearning}
 * with kind='memory', exactly as the Stop hook does).
 *
 * DELTA-SURPRISE filter (anti-inflation, first line): only a PROVEN completion — a
 * success that actually LANDED (a commit or real evidence) — carries a novel learning
 * worth memorising; a bare success or a failure produces null. The write-back's own
 * capture quality gate + dedup are the second line, so this never floods the registry.
 * The candidate ALWAYS lands status='proposed' (pending_review) — never auto-applied here.
 */
final class TaskOutcomeLearningCandidate
{
    /**
     * @param  array<string,mixed>  $task  the served packet (objective, allowed_files)
     * @param  array<string,mixed>  $payload  the report payload (outcome, commit, evidence)
     * @return array{kind:string,summary:string,evidence_refs:list<string>}|null
     */
    public static function from(array $task, array $payload): ?array
    {
        if ((string) ($payload['outcome'] ?? 'success') !== 'success') {
            return null; // only proven completions memorialise
        }

        $commit = trim((string) ($payload['commit'] ?? $payload['commit_sha'] ?? ''));
        $evidence = (array) ($payload['evidence'] ?? []);
        if ($commit === '' && $evidence === []) {
            return null; // delta-surprise: a success with no landed proof is not novel
        }

        $objective = trim((string) ($task['objective'] ?? ''));
        if ($objective === '') {
            return null;
        }

        $files = array_values(array_filter(array_map('strval', (array) ($task['allowed_files'] ?? [])), static fn ($f) => $f !== ''));
        $refs = array_values(array_filter(array_merge(
            $commit !== '' ? ['commit:'.$commit] : [],
            $files,
        )));
        if ($refs === []) {
            return null; // proposeLearning requires ≥1 evidence ref
        }

        return [
            'kind' => 'memory',
            'summary' => mb_substr('esteira outcome: '.$objective, 0, 280),
            'evidence_refs' => array_slice($refs, 0, 10),
        ];
    }
}

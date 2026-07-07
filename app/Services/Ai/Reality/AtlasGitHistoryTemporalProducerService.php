<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use Illuminate\Support\Facades\Process;

/**
 * T4-S1 (Obra #17) — bi-temporal PRODUCER.
 *
 * Walks the repository's git history and emits AURG-4D ticks carrying VALID
 * time (`valid_at` = each commit's committer date — "when the truth held in the
 * code") alongside the transaction time the tick log already records. This is
 * the producer the AURG-4D substrate ({@see AtlasUnifiedRealityGraphTemporalService}
 * — `stateAt`/`traverseTime` plus the new `stateAtValid`) was missing: the log
 * was mono-temporal until now.
 *
 * Idempotent: a commit already represented by a tick (matched on `snapshot_hash`
 * = commit sha) is skipped, so re-running never duplicates. Bounded by $limit.
 *
 * ponytail: recordTick() re-reads the whole log per append (O(n) each), so a
 * backfill is O(n·limit). Fine for a bounded one-shot; batch-append if an
 * unbounded backfill is ever needed.
 */
final class AtlasGitHistoryTemporalProducerService
{
    /** ASCII unit separator — safe git --format delimiter (never in a sha/date/subject line). */
    private const SEP = "\x1f";

    public function __construct(
        private readonly AtlasUnifiedRealityGraphTemporalService $temporal,
    ) {}

    /**
     * Emit one bi-temporal tick per HEAD-reachable commit (newest $limit),
     * valid_at = committer date. Idempotent + bounded.
     *
     * @return array{scanned:int, emitted:int, skipped:int, first_valid_at:?string, last_valid_at:?string}
     */
    public function backfill(int $limit = 500, ?string $repoRoot = null): array
    {
        $limit = $limit > 0 ? $limit : 500;
        $root = $repoRoot ?? base_path();

        $commits = $this->readGitLog($root, $limit);

        // Idempotency: any commit sha already recorded as a tick's snapshot_hash
        // is skipped — a second run over the same history is a no-op.
        $seen = [];
        foreach (($this->temporal->timeline(0)['ticks'] ?? []) as $tick) {
            $sha = (string) ($tick['snapshot_hash'] ?? '');
            if ($sha !== '') {
                $seen[$sha] = true;
            }
        }

        $emitted = 0;
        $skipped = 0;
        $firstValid = null;
        $lastValid = null;

        // Oldest → newest, so append/file order tracks chronology (ties resolve
        // to the later-recorded tick in stateAtValid, matching git's ancestry).
        foreach (array_reverse($commits) as $commit) {
            if (isset($seen[$commit['sha']])) {
                $skipped++;

                continue;
            }
            $this->temporal->recordTick([
                'kind' => AtlasUnifiedRealityGraphTemporalService::KIND_SNAPSHOT_RECORDED,
                'actor' => 'atlas',
                'snapshot_hash' => $commit['sha'],
                'valid_at' => $commit['date'],
                'rationale' => $commit['subject'],
            ]);
            $seen[$commit['sha']] = true;
            $emitted++;
            $firstValid ??= $commit['date'];
            $lastValid = $commit['date'];
        }

        return [
            'scanned' => count($commits),
            'emitted' => $emitted,
            'skipped' => $skipped,
            'first_valid_at' => $firstValid,
            'last_valid_at' => $lastValid,
        ];
    }

    /**
     * @return list<array{sha:string, date:string, subject:string}>
     */
    private function readGitLog(string $root, int $limit): array
    {
        $result = Process::path($root)->run([
            'git', 'log', 'HEAD', '-n', (string) $limit,
            '--format=%H'.self::SEP.'%cI'.self::SEP.'%s',
        ]);
        if (! $result->successful()) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(self::SEP, $line);
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $out[] = [
                'sha' => $parts[0],
                'date' => $parts[1],
                'subject' => mb_substr((string) ($parts[2] ?? ''), 0, 300),
            ];
        }

        return $out;
    }
}

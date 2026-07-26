<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use Throwable;

/**
 * Turns the Dev receipts already persisted per run ({@see AtlasDevFastPathOrchestrator})
 * into a replay library: real, proven examples any model can be boosted with, instead of a blank
 * page. This is a pure READ MODEL over the existing `verification_receipt.json` artifact under
 * `<receipts_path>/<run_id>/` — it never re-persists or duplicates the store, and it strips every
 * raw prompt/provider payload field (the receipt already stores only hashes for those, so nothing
 * needs redacting beyond selecting a compact projection).
 *
 * A run is a candidate exemplar only when its `completion.status` is `passed` (green). Candidates
 * rank by overlap with the requested task_kind, design_path, and likely file paths — a matching
 * task_kind/design_path/file scores higher, an unrelated green run still ranks (just lower), so
 * the caller always gets its top `$limit` best-available proven examples.
 *
 * INDEX SIDECAR (`exemplar_index.jsonl` in the store root): the store is append-only audit
 * evidence and grows unbounded, so an uncapped dir scan is O(all runs). The retriever maintains a
 * lazy compact index — every scanned run appends one row (green: the full exemplar projection;
 * non-green: a tombstone so it is never re-read). Retrieval candidates = indexed green rows ∪ the
 * newest scanned-this-call rows, so history STAYS retrievable beyond the scan cap once indexed
 * (run `indexAll()` / `atlas:dev:exemplar-index` once to backfill the historical store), while
 * per-call disk reads stay capped at DEFAULT_SCAN_CAP unindexed dirs.
 *
 * Fail-open by construction: an unreadable store, a missing/corrupt receipt for one run, an
 * unwritable index, or any parse error skips that run (or returns an empty list for a wholly
 * unreadable store) — this retriever never throws out of retrieve().
 */
final class DevGreenRunExemplarRetriever
{
    public const SCHEMA = 'atlas.dev.discovery.green_run_exemplar_retriever.v1';

    private const RECEIPT_FILENAME = 'verification_receipt.json';

    private const INDEX_FILENAME = 'exemplar_index.jsonl';

    /**
     * Newest UNINDEXED run dirs examined per retrieval (measured: ~131ms per
     * call at 5k dirs uncapped). Run ids start with a millisecond timestamp
     * (dev-<ms>-<rand>), so reverse-lexical order is newest-first.
     */
    public const DEFAULT_SCAN_CAP = 300;

    public function __construct(
        private readonly ?string $baseDirOverride = null,
        private readonly int $scanCap = self::DEFAULT_SCAN_CAP,
    ) {}

    /**
     * @param  list<string>  $likelyFiles
     * @return list<array{run_id:string, objective_digest:string, design_path:string, files_touched:list<string>, verification_command:string, outcome:string}>
     */
    public function retrieve(string $taskKind, string $designPath, array $likelyFiles, int $limit = 3, ?string $workspaceHash = null, ?string $originHash = null): array
    {
        try {
            return $this->retrieveInternal($taskKind, $designPath, $likelyFiles, max(0, $limit), $workspaceHash, $originHash);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Backfill: index EVERY not-yet-indexed run dir in one pass (O(all runs),
     * run once via `atlas:dev:exemplar-index`; retrievals then serve the whole
     * history at O(index) with per-call scans capped).
     *
     * @return array{indexed_green:int, indexed_other:int, already_indexed:int, unreadable:int}
     */
    public function indexAll(): array
    {
        $out = ['indexed_green' => 0, 'indexed_other' => 0, 'already_indexed' => 0, 'unreadable' => 0];
        $baseDir = $this->resolveBaseDir();
        if ($baseDir === '' || ! is_dir($baseDir)) {
            return $out;
        }

        $indexed = $this->loadIndex($baseDir);
        $fresh = [];
        foreach (scandir($baseDir, SCANDIR_SORT_DESCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::INDEX_FILENAME) {
                continue;
            }
            if (isset($indexed[$entry])) {
                $out['already_indexed']++;

                continue;
            }
            if (! is_dir($baseDir.DIRECTORY_SEPARATOR.$entry)) {
                continue;
            }
            $row = $this->readRow($baseDir.DIRECTORY_SEPARATOR.$entry, $entry);
            if ($row === null) {
                $out['unreadable']++;

                continue;
            }
            $fresh[$entry] = $row;
            $out[$row['outcome'] === 'passed' ? 'indexed_green' : 'indexed_other']++;
        }
        $this->appendToIndex($baseDir, $fresh);

        return $out;
    }

    /**
     * @param  list<string>  $likelyFiles
     * @return list<array{run_id:string, objective_digest:string, design_path:string, files_touched:list<string>, verification_command:string, outcome:string}>
     */
    private function retrieveInternal(string $taskKind, string $designPath, array $likelyFiles, int $limit, ?string $workspaceHash = null, ?string $originHash = null): array
    {
        $baseDir = $this->resolveBaseDir();
        if ($baseDir === '' || ! is_dir($baseDir)) {
            return [];
        }

        $likelyFiles = array_values(array_filter(array_map('strval', $likelyFiles)));
        $indexed = $this->loadIndex($baseDir);

        // Newest-first; only UNINDEXED dirs cost disk reads, capped.
        $fresh = [];
        $scanned = 0;
        foreach (scandir($baseDir, SCANDIR_SORT_DESCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::INDEX_FILENAME || isset($indexed[$entry])) {
                continue;
            }
            if ($scanned >= max(1, $this->scanCap)) {
                break;
            }
            $scanned++;
            $runDir = $baseDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($runDir)) {
                continue;
            }
            $row = $this->readRow($runDir, $entry);
            if ($row === null) {
                continue; // unreadable / mid-write: not indexed, retried next call
            }
            $fresh[$entry] = $row;
        }
        $this->appendToIndex($baseDir, $fresh);

        $scored = [];
        foreach ([$indexed, $fresh] as $rows) {
            foreach ($rows as $row) {
                if (($row['outcome'] ?? '') !== 'passed') {
                    continue;
                }
                if (! $this->matchesCallerIdentity($row, $workspaceHash, $originHash)) {
                    continue;
                }
                $scored[] = [
                    '_score' => $this->scoreRow($row, $taskKind, $designPath, $likelyFiles),
                    'exemplar' => [
                        'run_id' => (string) $row['run_id'],
                        'objective_digest' => (string) ($row['objective_digest'] ?? ''),
                        'objective_excerpt' => (string) ($row['objective_excerpt'] ?? ''),
                        'design_path' => (string) ($row['design_path'] ?? ''),
                        'files_touched' => array_values(array_map('strval', (array) ($row['files_touched'] ?? []))),
                        'verification_command' => (string) ($row['verification_command'] ?? ''),
                        'outcome' => 'passed',
                    ],
                ];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['_score'] === $a['_score']
            ? strcmp($a['exemplar']['run_id'], $b['exemplar']['run_id'])
            : $b['_score'] <=> $a['_score']);

        return array_values(array_map(
            static fn (array $row): array => $row['exemplar'],
            array_slice($scored, 0, $limit),
        ));
    }

    /** @param  list<string>  $likelyFiles */
    private function scoreRow(array $row, string $taskKind, string $designPath, array $likelyFiles): int
    {
        $score = 0;
        if ($taskKind !== '' && (string) ($row['task_kind'] ?? '') === $taskKind) {
            $score += 3;
        }
        if ($designPath !== '' && (string) ($row['design_path'] ?? '') === $designPath) {
            $score += 2;
        }

        return $score + count(array_intersect(
            array_values(array_map('strval', (array) ($row['files_touched'] ?? []))),
            $likelyFiles,
        ));
    }

    /**
     * Workspace anti-bleed (mirrors the M5 VAL-M5-007 strictness): when the
     * caller identifies itself, only exemplars from the SAME workspace OR the
     * same REPO ORIGIN are eligible. Two identities because the envelope
     * workspace is the CHECKOUT PATH — a per-run temp dir in the sandboxed
     * flows, so exact workspace_hash equality alone is mathematically empty
     * there. An unattributable row is excluded. Null caller identity keeps
     * unfiltered behavior.
     */
    private function matchesCallerIdentity(array $row, ?string $workspaceHash, ?string $originHash): bool
    {
        $callerHasIdentity = ($workspaceHash !== null && $workspaceHash !== '')
            || ($originHash !== null && $originHash !== '');
        if (! $callerHasIdentity) {
            return true;
        }
        if ($workspaceHash !== null && $workspaceHash !== '' && (string) ($row['workspace_hash'] ?? '') === $workspaceHash) {
            return true;
        }

        return $originHash !== null && $originHash !== '' && (string) ($row['origin_hash'] ?? '') === $originHash;
    }

    /**
     * One compact index row per run dir: the full exemplar projection for a
     * green run, a tombstone (`outcome` only) for anything else, or null when
     * the receipt is missing/corrupt (possibly mid-write — never indexed).
     *
     * @return array<string,mixed>|null
     */
    private function readRow(string $runDir, string $fallbackRunId): ?array
    {
        $path = $runDir.DIRECTORY_SEPARATOR.self::RECEIPT_FILENAME;
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $runId = (string) ($decoded['run_id'] ?? $fallbackRunId);
        $status = (string) ($decoded['completion']['status'] ?? '');
        if ($status !== 'passed') {
            return ['run_id' => $runId, 'dir' => $fallbackRunId, 'outcome' => $status === '' ? 'unknown' : $status];
        }

        $verificationCommand = '';
        foreach ((array) ($decoded['tests'] ?? []) as $test) {
            if (is_array($test) && (string) ($test['command'] ?? '') !== '') {
                $verificationCommand = (string) $test['command'];

                break;
            }
        }

        $objectiveDigest = (string) ($decoded['task_contract_hash'] ?? '');
        if ($objectiveDigest === '') {
            $objectiveDigest = hash('sha256', $runId);
        }

        $originPath = $runDir.DIRECTORY_SEPARATOR.'workspace_origin.json';
        $originRaw = is_file($originPath) ? @file_get_contents($originPath) : false;
        $originDecoded = $originRaw === false ? null : json_decode($originRaw, true);

        return [
            'run_id' => $runId,
            'dir' => $fallbackRunId,
            'outcome' => 'passed',
            'task_kind' => (string) ($decoded['task_kind'] ?? ''),
            'design_path' => (string) ($decoded['design_path'] ?? ''),
            'files_touched' => array_values(array_filter(array_map('strval', (array) ($decoded['changed_files'] ?? [])))),
            'verification_command' => $verificationCommand,
            'objective_digest' => $objectiveDigest,
            'objective_excerpt' => $this->objectiveExcerpt($runDir),
            'workspace_hash' => (string) ($decoded['workspace_hash'] ?? ''),
            'origin_hash' => is_array($originDecoded) ? (string) ($originDecoded['origin_hash'] ?? '') : '',
        ];
    }

    /** @return array<string,array<string,mixed>> keyed by run DIR name */
    private function loadIndex(string $baseDir): array
    {
        $path = $baseDir.DIRECTORY_SEPARATOR.self::INDEX_FILENAME;
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['dir'] ?? '') !== '') {
                $rows[(string) $decoded['dir']] = $decoded;
            }
        }

        return $rows;
    }

    /** @param  array<string,array<string,mixed>>  $rows */
    private function appendToIndex(string $baseDir, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        try {
            $lines = '';
            foreach ($rows as $row) {
                $lines .= json_encode($row, JSON_UNESCAPED_SLASHES)."\n";
            }
            @file_put_contents($baseDir.DIRECTORY_SEPARATOR.self::INDEX_FILENAME, $lines, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // fail-open: an unwritable index never breaks retrieval
        }
    }

    /**
     * The human-readable objective of the exemplar run, read from the
     * sibling mini_programming_spec.json's `goal`. An exemplar whose only
     * identity is an opaque task_contract_hash teaches a model nothing —
     * "what this proven run DID" is the whole point of a replay library.
     * Same provider-sensitivity class as the current run's goal (which
     * already ships in the prompt's Objective section). Missing/corrupt
     * spec => empty string (fail-open, exemplar still usable).
     */
    private function objectiveExcerpt(string $runDir): string
    {
        $path = $runDir.DIRECTORY_SEPARATOR.'mini_programming_spec.json';
        if (! is_file($path)) {
            return '';
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return '';
        }

        $decoded = json_decode($raw, true);
        $goal = is_array($decoded) ? trim((string) ($decoded['goal'] ?? '')) : '';
        if ($goal === '') {
            return '';
        }

        return mb_strlen($goal) > 160 ? mb_substr($goal, 0, 157).'...' : $goal;
    }

    private function resolveBaseDir(): string
    {
        if ($this->baseDirOverride !== null) {
            return $this->baseDirOverride;
        }

        try {
            $configured = trim((string) config('atlas_dev.receipts_path'));
        } catch (Throwable) {
            $configured = '';
        }

        if ($configured !== '') {
            return $configured;
        }

        return function_exists('storage_path') ? storage_path('atlas-dev/receipts') : '';
    }
}

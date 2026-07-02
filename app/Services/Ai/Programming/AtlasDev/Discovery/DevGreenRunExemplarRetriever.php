<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use Throwable;

/**
 * Turns the Dev receipts already persisted per run ({@see \App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator})
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
 * Fail-open by construction: an unreadable store, a missing/corrupt receipt for one run, or any
 * parse error skips that run (or returns an empty list for a wholly unreadable store) — this
 * retriever never throws out of retrieve().
 */
final class DevGreenRunExemplarRetriever
{
    public const SCHEMA = 'atlas.dev.discovery.green_run_exemplar_retriever.v1';

    private const RECEIPT_FILENAME = 'verification_receipt.json';

    /**
     * Newest run dirs examined per retrieval. The receipts store is
     * append-only audit evidence (never pruned by this reader) and grows
     * unbounded — an uncapped scan is O(all runs) and runs N+1 times per
     * planOnly (measured: ~131ms per call at 5k dirs). Run ids start with a
     * millisecond timestamp (dev-<ms>-<rand>), so reverse-lexical order is
     * newest-first; recent green runs are also the most relevant exemplars.
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

        // Newest-first, capped (see DEFAULT_SCAN_CAP): reverse-lexical order
        // of dev-<ms>-<rand> ids is reverse-chronological.
        $entries = array_values(array_filter(
            scandir($baseDir, SCANDIR_SORT_DESCENDING) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
        $entries = array_slice($entries, 0, max(1, $this->scanCap));

        $scored = [];
        foreach ($entries as $entry) {
            $runDir = $baseDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($runDir)) {
                continue;
            }

            $exemplar = $this->readExemplar($runDir, $entry, $taskKind, $designPath, $likelyFiles, $workspaceHash, $originHash);
            if ($exemplar !== null) {
                $scored[] = $exemplar;
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

    /**
     * @param  list<string>  $likelyFiles
     * @return array{_score:int, exemplar:array{run_id:string, objective_digest:string, design_path:string, files_touched:list<string>, verification_command:string, outcome:string}}|null
     */
    private function readExemplar(string $runDir, string $fallbackRunId, string $taskKind, string $designPath, array $likelyFiles, ?string $workspaceHash = null, ?string $originHash = null): ?array
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

        $status = (string) ($decoded['completion']['status'] ?? '');
        if ($status !== 'passed') {
            return null;
        }

        // Workspace anti-bleed (mirrors the M5 VAL-M5-007 strictness): when
        // the caller identifies itself, only exemplars from the SAME
        // workspace OR the same REPO ORIGIN are eligible. Two identities
        // because the envelope workspace is the CHECKOUT PATH — a per-run
        // temp dir in the sandboxed flows, so exact workspace_hash equality
        // alone is mathematically empty there (audited: 73 green receipts,
        // 73 distinct hashes). New runs persist a sibling
        // workspace_origin.json carrying the stable origin hash
        // ({@see \App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity});
        // legacy receipts without it still match via exact workspace_hash
        // (the stable-path operator flow). An unattributable receipt is
        // excluded. Null caller identity keeps unfiltered behavior.
        $callerHasIdentity = ($workspaceHash !== null && $workspaceHash !== '')
            || ($originHash !== null && $originHash !== '');
        if ($callerHasIdentity) {
            $matchesWorkspace = $workspaceHash !== null && $workspaceHash !== ''
                && (string) ($decoded['workspace_hash'] ?? '') === $workspaceHash;
            $matchesOrigin = false;
            if (! $matchesWorkspace && $originHash !== null && $originHash !== '') {
                $originPath = $runDir.DIRECTORY_SEPARATOR.'workspace_origin.json';
                $originRaw = is_file($originPath) ? @file_get_contents($originPath) : false;
                $originDecoded = $originRaw === false ? null : json_decode($originRaw, true);
                $matchesOrigin = is_array($originDecoded)
                    && (string) ($originDecoded['origin_hash'] ?? '') === $originHash;
            }
            if (! $matchesWorkspace && ! $matchesOrigin) {
                return null;
            }
        }

        $rowTaskKind = (string) ($decoded['task_kind'] ?? '');
        $rowDesignPath = (string) ($decoded['design_path'] ?? '');
        $changedFiles = array_values(array_filter(array_map('strval', (array) ($decoded['changed_files'] ?? []))));

        $score = 0;
        if ($taskKind !== '' && $rowTaskKind === $taskKind) {
            $score += 3;
        }
        if ($designPath !== '' && $rowDesignPath === $designPath) {
            $score += 2;
        }
        $score += count(array_intersect($changedFiles, $likelyFiles));

        $verificationCommand = '';
        foreach ((array) ($decoded['tests'] ?? []) as $test) {
            if (is_array($test) && (string) ($test['command'] ?? '') !== '') {
                $verificationCommand = (string) $test['command'];

                break;
            }
        }

        $objectiveDigest = (string) ($decoded['task_contract_hash'] ?? '');
        if ($objectiveDigest === '') {
            $objectiveDigest = hash('sha256', (string) ($decoded['run_id'] ?? $fallbackRunId));
        }

        return [
            '_score' => $score,
            'exemplar' => [
                'run_id' => (string) ($decoded['run_id'] ?? $fallbackRunId),
                'objective_digest' => $objectiveDigest,
                'objective_excerpt' => $this->objectiveExcerpt($runDir),
                'design_path' => $rowDesignPath,
                'files_touched' => $changedFiles,
                'verification_command' => $verificationCommand,
                'outcome' => $status,
            ],
        ];
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

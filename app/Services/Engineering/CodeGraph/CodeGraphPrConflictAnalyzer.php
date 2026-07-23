<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Read-only PR merge-order risk analysis over code-graph communities (AP-811/AP-812 P-10).
 *
 * Technique captured (as technique, not authority) from the `graphify prs --conflicts`
 * dissection: two open PRs that touch files belonging to the SAME graph community are
 * a latent merge-conflict / semantic-coupling risk even when they touch different files
 * — the community boundary is the real unit of change, not the file. Surfacing those
 * shared communities lets a human pick a safe merge order BEFORE the conflict happens.
 *
 * Pure transform — no gh, no network, no DB, no IO, no provider, no Python runtime.
 * The caller supplies (a) a map of PR# -> changed file paths and (b) a resolver that
 * maps a file path to its community id (from the existing community/cluster read-model).
 * gh/network wiring is a later promotion slice; this analyzer stays a pure function so
 * it can be unit-tested deterministically and never decides anything on its own.
 *
 * @phpstan-type CommunityRisk array{community_id:string, prs:array<int,string>, file_count:int, pr_count:int}
 */
class CodeGraphPrConflictAnalyzer
{
    public const SCHEMA = 'atlas.code_graph.pr_conflicts.v1';

    /**
     * Communities touched by more than one PR are flagged as merge-order risks,
     * ranked by the number of PRs sharing them (desc), then community_id (asc) for
     * a deterministic tie-break.
     *
     * @param  array<int|string,array<int,string>>  $prChangedFiles  PR# (or PR key) -> list of changed file paths.
     * @param  callable(string):?string  $communityForFile  maps a changed file path to its community id;
     *   return null when the file has no known community (e.g. not yet indexed) so it is ignored.
     * @return array{schema_version:string, risks:array<int,CommunityRisk>, stats:array<string,int>}
     */
    public function conflictsByCommunity(array $prChangedFiles, callable $communityForFile): array
    {
        /** @var array<string,array<string,true>> $prsByCommunity community_id => set of PR keys */
        $prsByCommunity = [];
        /** @var array<string,array<string,true>> $filesByCommunity community_id => set of distinct file paths */
        $filesByCommunity = [];

        $stats = [
            'prs' => 0,
            'files_seen' => 0,
            'files_resolved' => 0,
            'files_unresolved' => 0,
            'communities_touched' => 0,
        ];

        foreach ($prChangedFiles as $prKey => $changedFiles) {
            if (! is_array($changedFiles)) {
                continue;
            }
            $pr = $this->prKey($prKey);
            if ($pr === null) {
                continue;
            }
            $stats['prs']++;

            // Dedupe files per PR so one PR cannot inflate a community's file_count.
            $seenForPr = [];
            foreach ($changedFiles as $file) {
                $path = $this->normalizePath($file);
                if ($path === null || isset($seenForPr[$path])) {
                    continue;
                }
                $seenForPr[$path] = true;
                $stats['files_seen']++;

                $community = $this->communityId($communityForFile($path));
                if ($community === null) {
                    $stats['files_unresolved']++;

                    continue;
                }
                $stats['files_resolved']++;

                $prsByCommunity[$community][$pr] = true;
                $filesByCommunity[$community][$path] = true;
            }
        }

        $stats['communities_touched'] = count($prsByCommunity);

        $risks = [];
        foreach ($prsByCommunity as $community => $prSet) {
            // A merge-order risk requires >1 distinct PR sharing the community.
            if (count($prSet) < 2) {
                continue;
            }

            // PR identifiers are normalized to strings (gh PR numbers and
            // branch/key forms must coexist). PHP re-casts numeric string array
            // keys to ints, so cast back to string at the output boundary for a
            // type-stable, deterministic contract.
            $prs = array_map(static fn (int|string $pr): string => (string) $pr, array_keys($prSet));
            sort($prs, SORT_NATURAL); // deterministic, stable PR listing

            $risks[] = [
                'community_id' => $community,
                'prs' => array_values($prs),
                'file_count' => count($filesByCommunity[$community] ?? []),
                'pr_count' => count($prSet),
            ];
        }

        usort(
            $risks,
            static fn (array $a, array $b): int => $b['pr_count'] <=> $a['pr_count']
                ?: $b['file_count'] <=> $a['file_count']
                ?: strcmp($a['community_id'], $b['community_id']),
        );

        return [
            'schema_version' => self::SCHEMA,
            'risks' => $risks,
            'stats' => $stats,
        ];
    }

    /**
     * Path-boundary-safe suffix match: does $path end with $suffix on a path
     * segment boundary? Used so a community's indexed path (which may be
     * repo-relative, e.g. "app/Services/Ai/Router.php") matches a PR's changed
     * file reported with a different leading prefix, WITHOUT the substring false
     * positive where "Bar.php" would otherwise match "FooBar.php".
     *
     * Exact equality matches. A suffix matches only when the character in $path
     * immediately preceding the suffix is a path separator ("/"). Empty inputs
     * never match.
     */
    public function pathSuffixMatches(string $path, string $suffix): bool
    {
        $path = $this->trimSeparators($path);
        $suffix = $this->trimSeparators($suffix);
        if ($path === '' || $suffix === '') {
            return false;
        }
        if ($path === $suffix) {
            return true;
        }

        $needle = '/'.$suffix;
        $needleLen = strlen($needle);
        $pathLen = strlen($path);
        if ($needleLen >= $pathLen) {
            // Suffix (with its required leading "/") cannot fit before $path's start
            // unless it equals $path, already handled above.
            return false;
        }

        return substr($path, $pathLen - $needleLen) === $needle;
    }

    private function prKey(int|string $prKey): ?string
    {
        if (is_int($prKey)) {
            return (string) $prKey;
        }
        $trimmed = trim($prKey);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = $this->trimSeparators($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function communityId(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalize backslashes to "/" and strip surrounding whitespace and leading/
     * trailing separators so suffix matching is boundary-stable across OS/prefix.
     */
    private function trimSeparators(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));

        return trim($value, '/');
    }
}

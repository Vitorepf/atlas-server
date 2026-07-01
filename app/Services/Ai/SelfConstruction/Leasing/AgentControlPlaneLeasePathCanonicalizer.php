<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Leasing;

/**
 * Pure canonicalizer that normalizes lease IDs, agent IDs, task prefixes, and
 * file paths deterministically. Provides safe prune-filter matching that refuses
 * empty broad filters and unrelated prefix matches.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AgentControlPlaneLeasePathCanonicalizer
{
    public const SCHEMA = 'atlas.leasing.lease_path_canonicalizer.v1';

    /**
     * Normalize a lease ID: lowercase, trim, remove special chars.
     */
    public function canonicalizeLeaseId(string $leaseId): string
    {
        $id = strtolower(trim($leaseId));
        $id = preg_replace('/[^a-z0-9_\-]/', '', $id) ?? $id;

        return $id;
    }

    /**
     * Normalize an agent ID: lowercase, trim.
     */
    public function canonicalizeAgentId(string $agentId): string
    {
        return strtolower(trim($agentId));
    }

    /**
     * Normalize a task prefix: lowercase, trim, ensure trailing slash.
     */
    public function canonicalizeTaskPrefix(string $prefix): string
    {
        $p = strtolower(trim($prefix));
        if ($p !== '' && ! str_ends_with($p, '/')) {
            $p .= '/';
        }

        return $p;
    }

    /**
     * Normalize a set of strings: trim, lowercase, unique, sort.
     *
     * @param  list<string>  $set
     * @return list<string>
     */
    public function normalizeSet(array $set): array
    {
        $normalized = array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $set
        );
        $normalized = array_filter($normalized, fn ($v) => $v !== '');
        $normalized = array_unique($normalized);
        sort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /**
     * Normalize a list of strings (without unique/dedup).
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        return array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), $values),
            fn ($v) => $v !== ''
        ));
    }

    /**
     * Derive the canonical lease storage path for a given lease ID.
     */
    public function leasePath(string $leaseId): string
    {
        return 'app/atlas/self-construction/leases/' . $this->canonicalizeLeaseId($leaseId) . '.json';
    }

    /**
     * Check if a lease entry matches any of the given prune filters.
     *
     * @param  array<string,mixed>  $entry
     * @param  list<string>  $taskPrefixes
     * @param  list<string>  $agentPrefixes
     * @param  list<string>  $leasePrefixes
     */
    public function leaseEntryMatchesPruneFilters(array $entry, array $taskPrefixes, array $agentPrefixes, array $leasePrefixes): bool
    {
        $entryLeaseId = $this->canonicalizeLeaseId((string) ($entry['lease_id'] ?? ''));
        $entryAgentId = $this->canonicalizeAgentId((string) ($entry['agent_id'] ?? ''));
        $entryTaskPrefix = $this->canonicalizeTaskPrefix((string) ($entry['task_prefix'] ?? ''));

        foreach ($this->normalizeSet($taskPrefixes) as $prefix) {
            if ($prefix !== '' && $this->safeMatch($prefix, $entryTaskPrefix)) {
                return true;
            }
        }

        foreach ($this->normalizeSet($agentPrefixes) as $prefix) {
            if ($prefix !== '' && $this->safeMatch($prefix, $entryAgentId)) {
                return true;
            }
        }

        foreach ($this->normalizeSet($leasePrefixes) as $prefix) {
            if ($prefix !== '' && $this->safeMatch($prefix, $entryLeaseId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a file path: forward slashes, no double slashes, no trailing slash.
     */
    public function canonicalizePath(string $path): string
    {
        $p = trim($path);
        $p = str_replace('\\', '/', $p);
        $p = preg_replace('#/{2,}#', '/', $p) ?? $p;
        $p = rtrim($p, '/');

        return $p;
    }

    /**
     * Safe prune-filter matching: returns true only when the target matches
     * the filter exactly or by safe prefix.
     *
     * Refuses empty/broad filters that would match everything.
     *
     * @param  string  $filter  The filter to match against
     * @param  string  $target  The target lease/path to check
     */
    public function safeMatch(string $filter, string $target): bool
    {
        $filter = $this->canonicalizePath($filter);
        $target = $this->canonicalizePath($target);

        // Refuse empty or root filters (would match everything = dangerous)
        if ($filter === '' || $filter === '/' || $filter === '.') {
            return false;
        }

        // Exact match
        if ($filter === $target) {
            return true;
        }

        // Prefix match: filter must be a parent directory of target
        // e.g. filter="app/Services" matches "app/Services/Foo.php"
        // but filter="app/Ser" must NOT match "app/Services" (partial segment)
        if (str_starts_with($target, $filter . '/')) {
            return true;
        }

        return false;
    }

    /**
     * Batch check: returns only targets that safe-match the filter.
     *
     * @param  string  $filter
     * @param  list<string>  $targets
     * @return list<string>
     */
    public function pruneFilter(string $filter, array $targets): array
    {
        $matched = [];
        foreach ($targets as $target) {
            if ($this->safeMatch($filter, $target)) {
                $matched[] = $target;
            }
        }

        return $matched;
    }
}

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
     * Canonicalize lease and queue paths against the same serving disk identity.
     *
     * @param  string  $leasePath  Path from the lease registry
     * @param  string  $queuePath  Path from the task queue
     * @return array{same_disk:bool,disk_mismatch:string|null,canonical_key:string}
     */
    public function canonicalizeAgainstSameDisk(string $leasePath, string $queuePath): array
    {
        $leaseCanonical = $this->canonicalizePath($leasePath);
        $queueCanonical = $this->canonicalizePath($queuePath);

        // Extract the serving disk identity (root directory) from both paths
        $leaseDisk = $this->extractServingDiskIdentity($leaseCanonical);
        $queueDisk = $this->extractServingDiskIdentity($queueCanonical);

        // Different textual paths that point to the same serving registry canonicalize to the same key
        if ($leaseDisk === $queueDisk) {
            $canonicalKey = $this->buildCanonicalKey($leaseDisk, $leaseCanonical);

            return [
                'same_disk' => true,
                'disk_mismatch' => null,
                'canonical_key' => $canonicalKey,
            ];
        }

        // Mismatched disk identities are reported as disk_mismatch instead of lease_leak
        return [
            'same_disk' => false,
            'disk_mismatch' => sprintf(
                'disk_mismatch:lease_disk=%s queue_disk=%s lease_path=%s queue_path=%s',
                $leaseDisk,
                $queueDisk,
                $leaseCanonical,
                $queueCanonical,
            ),
            'canonical_key' => '',
        ];
    }

    /**
     * Extract the serving disk identity (root directory) from a canonical path.
     */
    private function extractServingDiskIdentity(string $canonicalPath): string
    {
        // The serving disk identity is the root directory of the path
        // e.g. /var/atlas/leases/foo.json → /var/atlas
        $parts = explode('/', $canonicalPath);
        // Take the first 3 segments as the disk identity (e.g. /var/atlas)
        $depth = min(count($parts) - 2, 3);
        $diskParts = array_slice($parts, 0, max($depth, 1));

        return implode('/', $diskParts);
    }

    /**
     * Build a canonical key from disk identity and path.
     */
    private function buildCanonicalKey(string $diskIdentity, string $path): string
    {
        return $diskIdentity . ':' . $this->canonicalizePath($path);
    }

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
     *
     * MUST equal the repository's STORAGE_PREFIX: the split-claimlease-repo extraction shipped a
     * divergent hardcoded dir here, so writeLeaseFile/readLeaseFile landed in one directory while
     * rebuildRegistryFromLeaseFiles/prune scanned another — post-corruption rebuild recovered ZERO
     * leases and write-set conflicts were silently lost.
     */
    public function leasePath(string $leaseId): string
    {
        return \App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/'.$this->canonicalizeLeaseId($leaseId).'.json';
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
            if ($prefix !== '' && $this->safeIdentifierMatch($prefix, $entryTaskPrefix)) {
                return true;
            }
        }

        foreach ($this->normalizeSet($agentPrefixes) as $prefix) {
            if ($prefix !== '' && $this->safeIdentifierMatch($prefix, $entryAgentId)) {
                return true;
            }
        }

        foreach ($this->normalizeSet($leasePrefixes) as $prefix) {
            if ($prefix !== '' && $this->safeIdentifierMatch($prefix, $entryLeaseId)) {
                return true;
            }
        }

        return false;
    }

    /** Identifier prefixes are not filesystem paths: `agent-` must match `agent-001`. */
    private function safeIdentifierMatch(string $filter, string $target): bool
    {
        $filter = strtolower(trim($filter));
        $target = strtolower(trim($target));

        return $filter !== '' && $filter !== '/' && $filter !== '.'
            && ($filter === $target || str_starts_with($target, $filter));
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

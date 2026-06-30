<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Records high-effort searches that found no seedable value, preventing future originator
 * cycles from re-burning tokens on already-exhausted surfaces.
 *
 * OPERATIONS:
 *   record($entry)               — add or replace a negative finding
 *   evaluate($surface,$method,$now) — retry_allowed (freshness expired) | skip_surface (still fresh)
 *   deduplicate($surface,$method)   — true if already recorded
 *   list($options)               — all entries, optionally filtered
 *   purgeExpired($now)           — remove entries whose freshness has expired
 *
 * REJECTION (vague entries):
 *   Entry is rejected if any of surface, method, evidence, inspected_count (<1), or
 *   search_depth (<1) is absent or invalid.
 *
 * DEDUPLICATION KEY: (surface, method) pair. Recording the same pair replaces the old entry.
 *
 * FRESHNESS / RETRY:
 *   expires_at = recorded_at + ttl_seconds (default 86400 = 24h)
 *   evaluate → retry_allowed  when now >= expires_at  OR  any retry_condition is met
 *   evaluate → skip_surface   when now <  expires_at  AND no retry_condition met
 *
 * RETRY CONDITIONS (caller-supplied strings, checked as boolean flags):
 *   The ledger stores them as-is; retry_condition matching is evaluated externally.
 *   When the caller passes active_conditions list to evaluate(), any overlap → retry_allowed.
 *
 * INPUT RECORD:
 *   {
 *     surface:           string  (what was searched — e.g. "github_issues", "arxiv")
 *     method:            string  (how it was searched — e.g. "keyword_scan", "semantic_search")
 *     evidence:          string  (what was actually inspected — non-empty required)
 *     inspected_count:   int     (number of artifacts inspected — must be >= 1)
 *     search_depth:      int     (levels / iterations of search — must be >= 1)
 *     reason?:           string  (why no value was found)
 *     recorded_at?:      int     (unix timestamp; default 0)
 *     ttl_seconds?:      int     (freshness window; default 86400)
 *     retry_conditions?: list<string>
 *   }
 *
 * OUTPUT list():
 *   { schema, entries, count }
 *
 * OUTPUT record():
 *   { schema, accepted: bool, entry?: array, rejection_reason?: string }
 *
 * OUTPUT evaluate():
 *   { schema, decision: retry_allowed|skip_surface, reason: string }
 *
 * PURE / DETERMINISTIC / NO I/O. State is held in-memory (single instance).
 */
final class AtlasExternalBrainNegativeResultLedger
{
    public const SCHEMA = 'atlas.external_brain.negative_result_ledger.v1';

    public const DECISION_RETRY_ALLOWED = 'retry_allowed';
    public const DECISION_SKIP_SURFACE  = 'skip_surface';

    private const DEFAULT_TTL_SECONDS = 86400;

    /** @var array<string,array<string,mixed>> keyed by dedup key */
    private array $entries = [];

    /**
     * Record a negative finding.  Replaces any prior entry with the same (surface, method) pair.
     *
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function record(array $entry): array
    {
        $surface  = trim((string) ($entry['surface'] ?? ''));
        $method   = trim((string) ($entry['method'] ?? ''));
        $evidence = trim((string) ($entry['evidence'] ?? ''));

        if ($surface === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'surface_missing'];
        }
        if ($method === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'method_missing'];
        }
        if ($evidence === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'evidence_missing'];
        }

        $inspectedCount = isset($entry['inspected_count']) ? (int) $entry['inspected_count'] : 0;
        $searchDepth    = isset($entry['search_depth'])    ? (int) $entry['search_depth']    : 0;

        if ($inspectedCount < 1) {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'inspected_count_missing'];
        }
        if ($searchDepth < 1) {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'search_depth_missing'];
        }

        $recordedAt      = (int) ($entry['recorded_at'] ?? 0);
        $ttl             = max(1, (int) ($entry['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS));
        $retryConditions = is_array($entry['retry_conditions'] ?? null) ? array_values($entry['retry_conditions']) : [];

        $stored = [
            'surface'          => $surface,
            'method'           => $method,
            'evidence'         => $evidence,
            'inspected_count'  => $inspectedCount,
            'search_depth'     => $searchDepth,
            'reason'           => (string) ($entry['reason'] ?? ''),
            'recorded_at'      => $recordedAt,
            'ttl_seconds'      => $ttl,
            'expires_at'       => $recordedAt + $ttl,
            'retry_conditions' => $retryConditions,
        ];

        $key = $this->dedupKey($surface, $method);
        $this->entries[$key] = $stored;

        return ['schema' => self::SCHEMA, 'accepted' => true, 'entry' => $stored];
    }

    /**
     * Returns true when a finding for this (surface, method) pair already exists.
     */
    public function isDuplicate(string $surface, string $method): bool
    {
        return isset($this->entries[$this->dedupKey($surface, $method)]);
    }

    /**
     * Evaluate whether a surface+method should be retried or skipped.
     *
     * @param  list<string>  $activeConditions  Caller-supplied conditions that are currently true.
     * @return array<string,mixed>
     */
    public function evaluate(string $surface, string $method, int $now = 0, array $activeConditions = []): array
    {
        $key = $this->dedupKey($surface, $method);

        if (! isset($this->entries[$key])) {
            return [
                'schema'   => self::SCHEMA,
                'decision' => self::DECISION_RETRY_ALLOWED,
                'reason'   => 'not_in_ledger',
            ];
        }

        $entry = $this->entries[$key];

        // Freshness expired → retry allowed.
        if ($now >= $entry['expires_at']) {
            return [
                'schema'   => self::SCHEMA,
                'decision' => self::DECISION_RETRY_ALLOWED,
                'reason'   => 'freshness_expired',
            ];
        }

        // Any active condition matches a retry condition → retry allowed.
        $matchedConditions = array_intersect($activeConditions, $entry['retry_conditions']);
        if ($matchedConditions !== []) {
            return [
                'schema'   => self::SCHEMA,
                'decision' => self::DECISION_RETRY_ALLOWED,
                'reason'   => 'retry_condition_met:'.implode(',', array_values($matchedConditions)),
            ];
        }

        return [
            'schema'   => self::SCHEMA,
            'decision' => self::DECISION_SKIP_SURFACE,
            'reason'   => 'still_fresh_until:'.$entry['expires_at'],
        ];
    }

    /**
     * List all entries, optionally filtered by surface.
     *
     * @param  array<string,mixed>  $options  { surface?: string }
     * @return array<string,mixed>
     */
    public function list(array $options = []): array
    {
        $filterSurface = isset($options['surface']) ? trim((string) $options['surface']) : null;

        $entries = array_values($this->entries);

        if ($filterSurface !== null && $filterSurface !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $e): bool => $e['surface'] === $filterSurface,
            ));
        }

        return [
            'schema'  => self::SCHEMA,
            'entries' => $entries,
            'count'   => count($entries),
        ];
    }

    /**
     * Remove entries whose freshness window has passed.
     *
     * @return array<string,mixed>
     */
    public function purgeExpired(int $now): array
    {
        $before = count($this->entries);
        $this->entries = array_filter(
            $this->entries,
            fn (array $e): bool => $now < $e['expires_at'],
        );
        $purged = $before - count($this->entries);

        return ['schema' => self::SCHEMA, 'purged' => $purged, 'remaining' => count($this->entries)];
    }

    private function dedupKey(string $surface, string $method): string
    {
        return $surface.'||'.$method;
    }
}

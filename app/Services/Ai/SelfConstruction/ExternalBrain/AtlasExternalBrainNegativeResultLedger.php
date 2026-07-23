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

    public const AVOIDANCE_AVOID_TARGET  = 'avoid_target';
    public const AVOIDANCE_RETRY_ALLOWED = 'retry_allowed';

    private const DEFAULT_TTL_SECONDS = 86400;
    private const DEFAULT_OUTCOME_TTL_SECONDS = 86400;

    /** @var array<string,array<string,mixed>> keyed by dedup key */
    private array $entries = [];

    /** @var array<string,array<string,mixed>> keyed by family||target */
    private array $outcomeEntries = [];

    /** Root causes that can never be fixed by retrying the same target — permanent avoid. */
    private const PERMANENT_ROOT_CAUSES = [
        'poison',
        'contradictory_acceptance',
        'capability_already_exists',
        'forbidden_target',
    ];

    /** Root causes that are situational and may clear on their own — temporary avoid. */
    private const TEMPORARY_ROOT_CAUSES = [
        'missing_dependency',
        'stale_context',
    ];

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
            'surface'                => $surface,
            'method'                 => $method,
            'evidence'               => $evidence,
            'inspected_count'        => $inspectedCount,
            'search_depth'           => $searchDepth,
            'reason'                 => (string) ($entry['reason'] ?? ''),
            'recorded_at'            => $recordedAt,
            'ttl_seconds'            => $ttl,
            'expires_at'             => $recordedAt + $ttl,
            'retry_after'            => $recordedAt + $ttl,
            'retry_conditions'       => $retryConditions,
            'invalidation_conditions' => $retryConditions,
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
            $reason = 'freshness_expired';

            return [
                'schema'              => self::SCHEMA,
                'decision'            => self::DECISION_RETRY_ALLOWED,
                'reason'              => $reason,
                'revalidation_reason' => $reason,
            ];
        }

        // Any active condition matches a retry condition → retry allowed.
        $matchedConditions = array_intersect($activeConditions, $entry['retry_conditions']);
        if ($matchedConditions !== []) {
            $reason = 'retry_condition_met:'.implode(',', array_values($matchedConditions));

            return [
                'schema'              => self::SCHEMA,
                'decision'            => self::DECISION_RETRY_ALLOWED,
                'reason'              => $reason,
                'revalidation_reason' => $reason,
            ];
        }

        return [
            'schema'                 => self::SCHEMA,
            'decision'               => self::DECISION_SKIP_SURFACE,
            'reason'                 => 'still_fresh_until:'.$entry['expires_at'],
            'anti_repeat_constraint' => $entry,
            // AC3: never hide a future opportunity forever — always say exactly when/under what
            // condition this surface+method becomes retryable again.
            'when_to_retry'          => $entry['retry_conditions'] !== []
                ? sprintf('at_or_after:%d OR when:%s', $entry['expires_at'], implode(',', $entry['retry_conditions']))
                : sprintf('at_or_after:%d', $entry['expires_at']),
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

    /**
     * Records a rejected spec, give_back, poisoned packet, or low-yield
     * search as reusable avoid-pattern learning. Replaces any prior entry
     * for the same (family, target) pair.
     *
     * permanence is derived from root_cause when not explicitly supplied:
     *   PERMANENT_ROOT_CAUSES (poison, contradictory_acceptance,
     *     capability_already_exists, forbidden_target) -> permanent
     *   TEMPORARY_ROOT_CAUSES (missing_dependency, stale_context) -> temporary
     *   anything else -> temporary (assume retryable unless proven otherwise)
     *
     * @param  array<string,mixed>  $entry  { family, target, root_cause,
     *   avoid_pattern, retry_after_condition?, permanence? }
     * @return array<string,mixed>
     */
    public function recordOutcome(array $entry): array
    {
        $family = trim((string) ($entry['family'] ?? ''));
        $target = trim((string) ($entry['target'] ?? ''));
        $rootCause = trim((string) ($entry['root_cause'] ?? ''));
        $avoidPattern = trim((string) ($entry['avoid_pattern'] ?? ''));
        $evidence = trim((string) ($entry['evidence'] ?? ''));

        if ($family === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'family_missing'];
        }
        if ($target === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'target_missing'];
        }
        if ($rootCause === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'root_cause_missing'];
        }
        if ($avoidPattern === '') {
            return ['schema' => self::SCHEMA, 'accepted' => false, 'rejection_reason' => 'avoid_pattern_missing'];
        }

        $permanence = trim((string) ($entry['permanence'] ?? ''));
        if ($permanence !== 'permanent' && $permanence !== 'temporary') {
            $permanence = in_array($rootCause, self::PERMANENT_ROOT_CAUSES, true) ? 'permanent' : 'temporary';
        }

        $recordedAt = (int) ($entry['recorded_at'] ?? 0);
        $ttl = max(1, (int) ($entry['ttl_seconds'] ?? self::DEFAULT_OUTCOME_TTL_SECONDS));

        $stored = [
            'family' => $family,
            'target' => $target,
            'root_cause' => $rootCause,
            'avoid_pattern' => $avoidPattern,
            'evidence' => $evidence,
            'recorded_at' => $recordedAt,
            'ttl_seconds' => $ttl,
            'expires_at' => $recordedAt + $ttl,
            'retry_after_condition' => (string) ($entry['retry_after_condition'] ?? ''),
            'permanence' => $permanence,
            'avoidance_decision' => self::AVOIDANCE_AVOID_TARGET,
        ];

        $this->outcomeEntries[$this->outcomeKey($family, $target)] = $stored;

        return ['schema' => self::SCHEMA, 'accepted' => true, 'entry' => $stored];
    }

    /**
     * Checks whether a specific (family, target) pair has a recorded avoid
     * rule. Never blocks unrelated families/targets — only an exact match
     * triggers avoid=true.
     *
     * Permanent root causes always avoid_target. Temporary root causes
     * avoid_target only until ttl expiry or a matching active retry condition —
     * after that they return retry_allowed.
     *
     * @param  list<string>  $activeConditions
     * @return array<string,mixed>
     */
    public function shouldAvoidTask(string $family, string $target, int $now = 0, array $activeConditions = []): array
    {
        $key = $this->outcomeKey($family, $target);
        if (! isset($this->outcomeEntries[$key])) {
            return ['schema' => self::SCHEMA, 'avoid' => false, 'rule' => null, 'avoidance_decision' => self::AVOIDANCE_RETRY_ALLOWED];
        }

        $rule = $this->outcomeEntries[$key];

        if ($rule['permanence'] === 'permanent') {
            return ['schema' => self::SCHEMA, 'avoid' => true, 'rule' => $rule, 'avoidance_decision' => self::AVOIDANCE_AVOID_TARGET];
        }

        $conditionMet = $rule['retry_after_condition'] !== '' && in_array($rule['retry_after_condition'], $activeConditions, true);
        $ttlExpired = $now >= $rule['expires_at'];

        if ($ttlExpired || $conditionMet) {
            return ['schema' => self::SCHEMA, 'avoid' => false, 'rule' => $rule, 'avoidance_decision' => self::AVOIDANCE_RETRY_ALLOWED];
        }

        return ['schema' => self::SCHEMA, 'avoid' => true, 'rule' => $rule, 'avoidance_decision' => self::AVOIDANCE_AVOID_TARGET];
    }

    /**
     * Summarizes recorded avoid rules for future Task Fabric admission,
     * optionally filtered by family and/or permanence.
     *
     * @param  array<string,mixed>  $options  { family?: string, permanence?: string }
     * @return array<string,mixed>
     */
    public function summarizeAvoidRules(array $options = []): array
    {
        $filterFamily = isset($options['family']) ? trim((string) $options['family']) : null;
        $filterPermanence = isset($options['permanence']) ? trim((string) $options['permanence']) : null;

        $rules = array_values($this->outcomeEntries);

        if ($filterFamily !== null && $filterFamily !== '') {
            $rules = array_values(array_filter($rules, fn (array $r): bool => $r['family'] === $filterFamily));
        }
        if ($filterPermanence !== null && $filterPermanence !== '') {
            $rules = array_values(array_filter($rules, fn (array $r): bool => $r['permanence'] === $filterPermanence));
        }

        return [
            'schema' => self::SCHEMA,
            'rules' => $rules,
            'count' => count($rules),
        ];
    }

    private function outcomeKey(string $family, string $target): string
    {
        return $family.'||'.$target;
    }
}

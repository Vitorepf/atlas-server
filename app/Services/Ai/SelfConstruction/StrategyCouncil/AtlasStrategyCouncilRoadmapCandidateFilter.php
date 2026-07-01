<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Pure filter — removes stale / duplicate / proxy / out-of-scope roadmap candidates BEFORE Strategy
 * selects work. Pure FACTS only — never decides priority, never schedules.
 *
 * INPUT (per candidate):
 *   { candidate_id, organ, capability, evidence_path, current_state, target_state, owner_scope,
 *     duplicate_key, resolved?:bool, kind?:string }
 *
 * DROP REASONS:
 *   - dropped:resolved                — resolved===true
 *   - dropped:quarantined             — quarantined===true
 *   - dropped:blocked_by_dead_prereq  — blocked_by_dead_prereq===true
 *   - dropped:poison_signature        — poison_signature===true
 *   - dropped:missing_evidence_path   — evidence_path empty
 *   - dropped:duplicate:<key>         — same duplicate_key already kept (first-wins)
 *   - dropped:proxy_only              — kind matches /cosmetic|proxy|whitespace|comment|cyclomatic/i
 *   - dropped:outside_scope:<owner>   — owner_scope ∉ admitted_owner_scopes
 *   - dropped:stale                   — stale===true
 *
 * OUTPUT:
 *   { schema, kept:list<candidate>, dropped:list<{candidate_id, drop_reason}> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: kept sorted by candidate_id, dropped sorted by candidate_id.
 *   - PURE.
 */
final class AtlasStrategyCouncilRoadmapCandidateFilter
{
    public const SCHEMA = 'atlas.strategycouncil.roadmap_candidate_filter.v1';

    public const PROXY_KIND_REGEX = '/cosmetic|proxy|whitespace|comment|cyclomatic/i';

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<string>  $admittedOwnerScopes  whitelist of owner_scope values (empty ⇒ all admitted)
     * @param  bool  $workerFloorLow  when true, candidates that cannot produce claimable work
     *   soon (missing implementation_scope, runnable_acceptance, or near_term_queue_feed_value)
     *   are deferred instead of admitted — roadmap-pretty work must not starve active muscles
     *   while the worker floor is breached.
     * @return array{schema:string, kept:list<array<string,mixed>>, dropped:list<array{candidate_id:string, drop_reason:string}>}
     */
    public function filter(array $candidates, array $admittedOwnerScopes = [], bool $workerFloorLow = false): array
    {
        $kept = [];
        $dropped = [];
        $seenDupKeys = [];

        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            $id = (string) ($c['candidate_id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ((bool) ($c['resolved'] ?? false)) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:resolved'];

                continue;
            }
            if ((bool) ($c['quarantined'] ?? false)) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:quarantined'];

                continue;
            }
            if ((bool) ($c['blocked_by_dead_prereq'] ?? false)) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:blocked_by_dead_prereq'];

                continue;
            }
            if ((bool) ($c['poison_signature'] ?? false)) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:poison_signature'];

                continue;
            }
            if (trim((string) ($c['evidence_path'] ?? '')) === '') {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:missing_evidence_path'];

                continue;
            }
            $kind = (string) ($c['kind'] ?? '');
            if ($kind !== '' && preg_match(self::PROXY_KIND_REGEX, $kind)) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:proxy_only'];

                continue;
            }
            $owner = (string) ($c['owner_scope'] ?? '');
            if ($admittedOwnerScopes !== [] && ! in_array($owner, $admittedOwnerScopes, true)) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:outside_scope:'.($owner === '' ? 'missing' : $owner)];

                continue;
            }
            if ((bool) ($c['stale'] ?? false)) {
                $isWorkerFloor = $kind === 'worker_floor';
                $hasFreshQueueRefs = trim((string) ($c['queue_health_ref'] ?? '')) !== ''
                    && trim((string) ($c['queued_targets_ref'] ?? '')) !== '';

                // Worker-floor replenishment candidates are exempt from the blanket stale drop ONLY
                // when backed by fresh queue evidence — stale context can never justify a wait or a
                // duplicate-enqueue decision on its own.
                if (! ($isWorkerFloor && $hasFreshQueueRefs)) {
                    $dropped[] = [
                        'candidate_id' => $id,
                        'drop_reason' => $isWorkerFloor ? 'dropped:stale_queue_context' : 'dropped:stale',
                    ];

                    continue;
                }
            }
            $dupKey = (string) ($c['duplicate_key'] ?? '');
            if ($workerFloorLow) {
                if (! (bool) ($c['implementation_scope'] ?? false)) {
                    $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'deferred:worker_floor_missing_implementation_scope'];

                    continue;
                }
                if (! (bool) ($c['runnable_acceptance'] ?? false)) {
                    $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'deferred:worker_floor_missing_runnable_acceptance'];

                    continue;
                }
                if (! (bool) ($c['near_term_queue_feed_value'] ?? false)) {
                    $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'deferred:worker_floor_no_near_term_queue_feed_value'];

                    continue;
                }
            }
            if ($dupKey !== '' && isset($seenDupKeys[$dupKey])) {
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:duplicate:'.$dupKey];

                continue;
            }
            if ($dupKey !== '') {
                $seenDupKeys[$dupKey] = true;
            }
            $kept[] = $c;
        }

        usort($kept, static fn (array $a, array $b): int => strcmp((string) ($a['candidate_id'] ?? ''), (string) ($b['candidate_id'] ?? '')));
        usort($dropped, static fn (array $a, array $b): int => strcmp($a['candidate_id'], $b['candidate_id']));

        [$admitted, $rejected, $review] = $this->classifyForCouncil($kept, $dropped);

        return [
            'schema' => self::SCHEMA,
            'kept' => $kept,
            'dropped' => $dropped,
            'admitted' => $admitted,
            'rejected' => $rejected,
            'review' => $review,
        ];
    }

    /**
     * Additive council-facing overlay: same underlying decisions as kept/dropped, re-expressed as
     * admitted / rejected / review with the explicit facts the Strategy Council reads — owner_scope,
     * autonomy_fit, evidence_path, worker_capacity, duplicate_reason. A candidate is only pulled into
     * 'review' when it explicitly signals ambiguity (needs_review=true) or was deferred by the
     * worker-floor check — everything hard-dropped is 'rejected'; everything else kept is 'admitted'.
     *
     * @param  list<array<string,mixed>>  $kept
     * @param  list<array{candidate_id:string, drop_reason:string}>  $dropped
     * @return array{0:list<array<string,mixed>>, 1:list<array<string,mixed>>, 2:list<array<string,mixed>>}
     */
    private function classifyForCouncil(array $kept, array $dropped): array
    {
        $admitted = [];
        $review = [];
        foreach ($kept as $c) {
            $facts = $this->councilFacts($c, null);
            if ((bool) ($c['needs_review'] ?? false)) {
                $review[] = $facts;

                continue;
            }
            $admitted[] = $facts;
        }

        $rejected = [];
        foreach ($dropped as $d) {
            $facts = $this->councilFacts(['candidate_id' => $d['candidate_id']], $d['drop_reason']);
            if (str_starts_with($d['drop_reason'], 'deferred:')) {
                $review[] = $facts;

                continue;
            }
            $rejected[] = $facts;
        }

        usort($admitted, static fn (array $a, array $b): int => strcmp((string) $a['candidate_id'], (string) $b['candidate_id']));
        usort($rejected, static fn (array $a, array $b): int => strcmp((string) $a['candidate_id'], (string) $b['candidate_id']));
        usort($review, static fn (array $a, array $b): int => strcmp((string) $a['candidate_id'], (string) $b['candidate_id']));

        return [$admitted, $rejected, $review];
    }

    /**
     * @param  array<string,mixed>  $c
     * @return array{candidate_id:string, owner_scope:string, autonomy_fit:string, evidence_path:string, worker_capacity:string, duplicate_reason:?string, reason:?string}
     */
    private function councilFacts(array $c, ?string $dropReason): array
    {
        $hasAutonomyMetadata = array_key_exists('autonomy_impact', $c) || array_key_exists('implementability', $c);

        return [
            'candidate_id' => (string) ($c['candidate_id'] ?? ''),
            'owner_scope' => (string) ($c['owner_scope'] ?? ''),
            'autonomy_fit' => (bool) ($c['needs_review'] ?? false)
                ? 'needs_review'
                : ($hasAutonomyMetadata ? 'fit' : 'default'),
            'evidence_path' => (string) ($c['evidence_path'] ?? ''),
            'worker_capacity' => $dropReason !== null && str_starts_with($dropReason, 'deferred:worker_floor')
                ? 'insufficient'
                : 'sufficient',
            'duplicate_reason' => $dropReason !== null && str_starts_with($dropReason, 'dropped:duplicate:') ? $dropReason : null,
            'reason' => $dropReason,
        ];
    }
}

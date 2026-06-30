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
     * @return array{schema:string, kept:list<array<string,mixed>>, dropped:list<array{candidate_id:string, drop_reason:string}>}
     */
    public function filter(array $candidates, array $admittedOwnerScopes = []): array
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
                $dropped[] = ['candidate_id' => $id, 'drop_reason' => 'dropped:stale'];

                continue;
            }
            $dupKey = (string) ($c['duplicate_key'] ?? '');
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

        return [
            'schema' => self::SCHEMA,
            'kept' => $kept,
            'dropped' => $dropped,
        ];
    }
}

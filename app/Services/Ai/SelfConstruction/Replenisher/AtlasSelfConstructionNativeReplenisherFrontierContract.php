<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

/**
 * Normalizes FRONTIER FACTS for the Atlas-native replenisher. Each frontier becomes a deterministic
 * record { frontier_id, owner_organ, target_scope, capability_gap, allowed_file_candidates,
 * acceptance_obligations, evidence_obligations, risk_class }.
 *
 * REJECTIONS (facts-only blocker list, never throws):
 *   - proxy_only_kind                    — kind matches /cosmetic|proxy|whitespace|comment|cyclomatic/i
 *   - missing_target_scope               — target_scope empty
 *   - cross_project_mixed                — allowed_file_candidates span more than one detected project_id
 *   - duplicate_frontier_id              — frontier_id already seen
 *
 * OUTPUT:
 *   { schema, accepted:list<frontier>, rejected:list<{frontier_id, blockers:list<string>}> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC ordering: accepted+rejected sorted by frontier_id.
 *   - PURE.
 */
final class AtlasSelfConstructionNativeReplenisherFrontierContract
{
    public const SCHEMA = 'atlas.replenisher.frontier_contract.v1';

    public const PROXY_KIND_REGEX = '/cosmetic|proxy|whitespace|comment|cyclomatic/i';

    public const RISK_DEFAULT = 'standard';

    /**
     * @param  list<array<string,mixed>>  $frontierFacts
     * @return array{schema:string, accepted:list<array<string,mixed>>, rejected:list<array{frontier_id:string, blockers:list<string>}>}
     */
    public function normalize(array $frontierFacts): array
    {
        $accepted = [];
        $rejected = [];
        $seenIds = [];

        foreach ($frontierFacts as $f) {
            if (! is_array($f)) {
                continue;
            }
            $id = trim((string) ($f['frontier_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $blockers = [];

            if (isset($seenIds[$id])) {
                $blockers[] = 'duplicate_frontier_id';
            }
            $seenIds[$id] = true;

            $kind = (string) ($f['kind'] ?? '');
            if ($kind !== '' && preg_match(self::PROXY_KIND_REGEX, $kind)) {
                $blockers[] = 'proxy_only_kind';
            }
            $targetScope = trim((string) ($f['target_scope'] ?? ''));
            if ($targetScope === '') {
                $blockers[] = 'missing_target_scope';
            }

            $allowed = is_array($f['allowed_file_candidates'] ?? null) ? array_values(array_filter(array_map('strval', $f['allowed_file_candidates']), static fn (string $s): bool => $s !== '')) : [];
            if ($allowed === []) {
                $blockers[] = 'missing_allowed_files';
            }

            $acceptance = is_array($f['acceptance_obligations'] ?? null) ? array_values(array_filter(array_map('strval', $f['acceptance_obligations']), static fn (string $s): bool => $s !== '')) : [];
            if ($acceptance === []) {
                $blockers[] = 'missing_acceptance';
            }

            $projectIds = [];
            foreach ($allowed as $p) {
                $pid = $this->detectProjectId($p);
                if ($pid !== null && ! in_array($pid, $projectIds, true)) {
                    $projectIds[] = $pid;
                }
            }
            if (count($projectIds) > 1) {
                $blockers[] = 'cross_project_mixed';
            }

            if ($blockers !== []) {
                sort($blockers, SORT_STRING);
                $rejected[] = ['frontier_id' => $id, 'blockers' => $blockers];

                continue;
            }

            $accepted[] = [
                'frontier_id' => $id,
                'owner_organ' => (string) ($f['owner_organ'] ?? ''),
                'target_scope' => $targetScope,
                'capability_gap' => (string) ($f['capability_gap'] ?? ''),
                'allowed_file_candidates' => $allowed,
                'acceptance_obligations' => $acceptance,
                'evidence_obligations' => is_array($f['evidence_obligations'] ?? null) ? array_values(array_map('strval', $f['evidence_obligations'])) : [],
                'risk_class' => (string) ($f['risk_class'] ?? self::RISK_DEFAULT),
            ];
        }

        usort($accepted, static fn (array $a, array $b): int => strcmp($a['frontier_id'], $b['frontier_id']));
        usort($rejected, static fn (array $a, array $b): int => strcmp($a['frontier_id'], $b['frontier_id']));

        return [
            'schema' => self::SCHEMA,
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    /**
     * Detect a project_id-like prefix from a path. Heuristic: '/repo/<id>/...' or 'app/<id>/...'.
     * Returns null when no project_id is detectable (path is single-project).
     */
    private function detectProjectId(string $path): ?string
    {
        if (preg_match('#^/repo/([^/]+)/#', $path, $m)) {
            return $m[1];
        }

        return null;
    }
}

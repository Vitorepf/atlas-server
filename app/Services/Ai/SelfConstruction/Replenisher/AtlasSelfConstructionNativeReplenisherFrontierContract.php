<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

/**
 * Pure contract gate that ensures accepted frontiers include implementation AND
 * test candidates plus runnable acceptance, preventing the native replenisher
 * from creating test-only or vague packets.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionNativeReplenisherFrontierContract
{
    public const SCHEMA = 'atlas.self_construction.native_replenisher_frontier_contract.v1';

    public const BATCH_SCHEMA = 'atlas.replenisher.frontier_contract.v1';

    public const PROXY_KIND_REGEX = '/cosmetic|proxy|whitespace|comment|cyclomatic/i';

    public const RISK_DEFAULT = 'standard';

    /**
     * Batch normalizer over FRONTIER FACTS — the API the native-replenisher command and
     * pipeline consume. r120 replaced this class with the per-frontier validate() floor
     * and silently deleted normalize(), breaking every caller (contract/run actions).
     * Reconciled: normalize() is restored AND applies r120's implementability floor to
     * each frontier (implementation+test candidates + runnable acceptance) on top of the
     * original blockers, so the hardening is preserved, not reverted.
     *
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

            $evidence = is_array($f['evidence_obligations'] ?? null) ? array_values(array_filter(array_map('strval', $f['evidence_obligations']), static fn (string $s): bool => $s !== '')) : [];
            if ($evidence === []) {
                $blockers[] = 'missing_evidence';
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

            // r120 implementability floor, mapped from the frontier-facts vocabulary:
            // impl vs test candidates split on the tests/ prefix; the runnable gate is
            // any acceptance obligation that names a runnable proof.
            $implFiles = array_values(array_filter($allowed, static fn (string $p): bool => ! str_starts_with($p, 'tests/')));
            $testFiles = array_values(array_filter($allowed, static fn (string $p): bool => str_starts_with($p, 'tests/')));
            $runnable = array_values(array_filter($acceptance, static fn (string $a): bool => (bool) preg_match('/phpunit|artisan|pest|exits?\s*0|test/i', $a)));
            $floor = $this->validate([
                'implementation_files' => $implFiles,
                'test_files' => $testFiles,
                'acceptance_criteria' => $acceptance,
                'runnable_gate' => $runnable[0] ?? '',
            ]);
            if (! (bool) $floor['accepted']) {
                $blockers = array_merge($blockers, $floor['blockers']);
            }

            if ($blockers !== []) {
                $blockers = array_values(array_unique($blockers));
                sort($blockers, SORT_STRING);
                $rejected[] = ['frontier_id' => $id, 'blockers' => $blockers];

                continue;
            }

            $accepted[] = [
                'frontier_id'             => $id,
                'owner_organ'             => (string) ($f['owner_organ'] ?? ''),
                'target_scope'            => $targetScope,
                'capability_gap'          => (string) ($f['capability_gap'] ?? ''),
                'maturity_gap'            => (string) ($f['maturity_gap'] ?? ''),
                'blockers'                => [],
                'next_unlock'             => (string) ($f['next_unlock'] ?? ''),
                'allowed_file_candidates' => $allowed,
                'acceptance_obligations'  => $acceptance,
                'evidence_obligations'    => $evidence,
                'risk_class'              => (string) ($f['risk_class'] ?? self::RISK_DEFAULT),
            ];
        }

        usort($accepted, static fn (array $a, array $b): int => strcmp($a['frontier_id'], $b['frontier_id']));
        usort($rejected, static fn (array $a, array $b): int => strcmp($a['frontier_id'], $b['frontier_id']));

        return [
            'schema' => self::BATCH_SCHEMA,
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    /**
     * Detect a project_id-like prefix from a path. Heuristic: '/repo/<id>/...'.
     * Returns null when no project_id is detectable (path is single-project).
     */
    private function detectProjectId(string $path): ?string
    {
        if (preg_match('#^/repo/([^/]+)/#', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  array{
     *   implementation_files?:list<string>,
     *   test_files?:list<string>,
     *   acceptance_criteria?:list<string>,
     *   runnable_gate?:?string,
     * }  $frontier
     * @return array{
     *   schema:string,
     *   accepted:bool,
     *   blockers:list<string>,
     * }
     */
    public function validate(array $frontier): array
    {
        $blockers = [];

        $implFiles = array_values(array_filter((array) ($frontier['implementation_files'] ?? [])));
        $testFiles = array_values(array_filter((array) ($frontier['test_files'] ?? [])));
        $acceptance = array_values(array_filter((array) ($frontier['acceptance_criteria'] ?? [])));
        $runnableGate = (string) ($frontier['runnable_gate'] ?? '');

        if ($implFiles === []) {
            $blockers[] = 'missing:implementation_files';
        }

        if ($testFiles === []) {
            $blockers[] = 'missing:test_files';
        }

        if ($acceptance === []) {
            $blockers[] = 'missing:acceptance_criteria';
        }

        if ($runnableGate === '') {
            $blockers[] = 'missing:runnable_gate';
        }

        if (count($blockers) > 0) {
            return $this->envelope(false, $blockers);
        }

        return $this->envelope(true, []);
    }

    /** @param  list<string>  $blockers */
    private function envelope(bool $accepted, array $blockers): array
    {
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $accepted,
            'blockers' => $blockers,
        ];
    }
}

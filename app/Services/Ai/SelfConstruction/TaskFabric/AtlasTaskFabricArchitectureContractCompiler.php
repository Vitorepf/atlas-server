<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

use RuntimeException;

/**
 * Pure compiler turning an approved ARCHITECTURE CONTRACT into one or more small atomic packet-spec
 * drafts. PURE: never enqueues, dispatches, shells, gits, or mutates storage — the caller routes the
 * drafts into the multi-project queue.
 *
 * INPUT CONTRACT:
 *   { contract_id, owner_scope, capability_gap, candidate_files:list<string>,
 *     acceptance_seed:list<string>, evidence_seed:list<string>, risk_class }
 *
 * OUTPUT: list<packet_spec>, each:
 *   { id, objective, allowed_files:list<string>, scope_in:list<string>,
 *     acceptance_criteria:list<string>, required_evidence:list<string>,
 *     rollback_hint:string, risk_class:string, spec_hash:string }
 *
 * INVARIANTS:
 *   - REJECTS: candidate_files entries that look like a broad directory (no '.', trailing '/'),
 *     empty acceptance_seed, empty evidence_seed, empty owner_scope, and capability_gap wording that
 *     conflicts with Atlas-native ownership (e.g. mentions of "external provider owns").
 *   - SPLITS into atomic drafts: one draft per (impl_file + adjacent test_file) pair when the
 *     candidate_files list mixes impl + test files; the impl draft depends_on nothing (caller wires
 *     dependencies via the dependency ladder).
 *   - DETERMINISTIC spec_hash: sha256 over canonical {contract_id, id, allowed_files, scope_in,
 *     acceptance_criteria, required_evidence, risk_class}.
 */
final class AtlasTaskFabricArchitectureContractCompiler
{
    public const SCHEMA = 'atlas.taskfabric.architecture_contract.v1';

    public const FORBIDDEN_OWNERSHIP_PHRASES = [
        'external provider owns',
        'human operator owns final runtime',
        'external_provider_runtime',
    ];

    public const ANTI_PROXY_KINDS = ['task_count', 'line_churn', 'green_self_report', 'cosmetic_docs', 'queue_count'];

    /**
     * @param  array{contract_id:string, owner_scope:string, capability_gap:string, candidate_files:list<string>, acceptance_seed:list<string>, evidence_seed:list<string>, risk_class?:string}  $contract
     * @return list<array{id:string, contract_id:string, objective:string, allowed_files:list<string>, scope_in:list<string>, acceptance_criteria:list<string>, required_evidence:list<string>, rollback_hint:string, risk_class:string, spec_hash:string}>
     */
    public function compile(array $contract): array
    {
        $contractId = trim((string) ($contract['contract_id'] ?? ''));
        $ownerScope = trim((string) ($contract['owner_scope'] ?? ''));
        $capabilityGap = trim((string) ($contract['capability_gap'] ?? ''));
        $candidates = is_array($contract['candidate_files'] ?? null) ? array_values(array_map('strval', $contract['candidate_files'])) : [];
        $acceptanceSeed = is_array($contract['acceptance_seed'] ?? null) ? array_values(array_map('strval', $contract['acceptance_seed'])) : [];
        $evidenceSeed = is_array($contract['evidence_seed'] ?? null) ? array_values(array_map('strval', $contract['evidence_seed'])) : [];
        $riskClass = trim((string) ($contract['risk_class'] ?? 'standard'));
        $dependencyHints = is_array($contract['dependency_hints'] ?? null)
            ? array_values(array_filter(array_map('strval', $contract['dependency_hints']), static fn ($s) => $s !== ''))
            : [];

        if ($contractId === '') {
            throw new RuntimeException('contract_compiler: missing-field:contract_id');
        }
        if ($ownerScope === '') {
            throw new RuntimeException('contract_compiler: missing-field:owner_scope');
        }
        if ($capabilityGap === '') {
            throw new RuntimeException('contract_compiler: missing-field:capability_gap');
        }
        if ($acceptanceSeed === []) {
            throw new RuntimeException('contract_compiler: missing-field:acceptance_seed');
        }
        if ($evidenceSeed === []) {
            throw new RuntimeException('contract_compiler: missing-field:evidence_seed');
        }
        $gapLower = strtolower($capabilityGap);
        foreach (self::FORBIDDEN_OWNERSHIP_PHRASES as $bad) {
            if (str_contains($gapLower, strtolower($bad))) {
                throw new RuntimeException('contract_compiler: capability_gap conflicts with Atlas-native ownership: '.$bad);
            }
        }
        foreach ($candidates as $path) {
            if ($path === '' || str_ends_with($path, '/') || ! str_contains(basename($path), '.')) {
                throw new RuntimeException('contract_compiler: broad directory rejected (must be a concrete file path): '.$path);
            }
        }

        // Pair impl files with adjacent test files; emit one draft per impl file.
        $tests = array_values(array_filter($candidates, static fn (string $p): bool => str_starts_with($p, 'tests/') || str_contains($p, '/Tests/')));
        $impls = array_values(array_filter($candidates, static fn (string $p): bool => ! (str_starts_with($p, 'tests/') || str_contains($p, '/Tests/'))));

        if ($impls === []) {
            // Tests-only contract — emit a single draft covering all the test files.
            return [$this->draft($contractId, 'tests-only', $contractId.'-tests', $candidates, $candidates, $acceptanceSeed, $evidenceSeed, $riskClass, $ownerScope, $capabilityGap, $dependencyHints)];
        }

        $drafts = [];
        foreach ($impls as $i => $impl) {
            $matchingTest = $this->matchingTestFor($impl, $tests);
            $allowed = $matchingTest === null ? [$impl] : [$impl, $matchingTest];
            $draftId = $contractId.'-p'.($i + 1);
            $drafts[] = $this->draft($contractId, $impl, $draftId, $allowed, [$impl], $acceptanceSeed, $evidenceSeed, $riskClass, $ownerScope, $capabilityGap, $dependencyHints);
        }

        return $drafts;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $scopeIn
     * @param  list<string>  $acceptance
     * @param  list<string>  $evidence
     * @param  list<string>  $dependencyHints
     * @return array<string,mixed>
     */
    private function draft(string $contractId, string $implLabel, string $id, array $allowedFiles, array $scopeIn, array $acceptance, array $evidence, string $riskClass, string $ownerScope, string $gap, array $dependencyHints): array
    {
        $objective = sprintf('Atlas-native implementation for %s (contract %s, owner_scope=%s): %s', $implLabel, $contractId, $ownerScope, $gap);
        $rollbackHint = 'revert_commit:'.$contractId.':'.$id;

        $minRefs = match ($riskClass) { 'high' => 2, 'critical' => 3, default => 1 };
        $taskConstraints = ['write_scope_restricted_to:'.implode(',', $allowedFiles), 'evidence_refs_min:'.$minRefs];
        if (in_array($riskClass, ['high', 'critical'], true)) {
            $taskConstraints[] = 'no_auto_merge';
        }
        $evidenceFloor = ['min_refs' => $minRefs, 'required_kinds' => $evidence, 'forbidden_kinds' => self::ANTI_PROXY_KINDS];
        $antiProxyClauses = array_map(static fn ($k) => 'forbidden_evidence_kind:'.$k, self::ANTI_PROXY_KINDS);

        $draft = [
            'id' => $id,
            'contract_id' => $contractId,
            'objective' => $objective,
            'allowed_files' => $allowedFiles,
            'scope_in' => $scopeIn,
            'acceptance_criteria' => $acceptance,
            'required_evidence' => $evidence,
            'rollback_hint' => $rollbackHint,
            'risk_class' => $riskClass,
            'task_constraints' => $taskConstraints,
            'dependency_hints' => $dependencyHints,
            'evidence_floor' => $evidenceFloor,
            'anti_proxy_clauses' => $antiProxyClauses,
        ];
        $canonical = [
            'contract_id' => $contractId,
            'id' => $id,
            'allowed_files' => $allowedFiles,
            'scope_in' => $scopeIn,
            'acceptance_criteria' => $acceptance,
            'required_evidence' => $evidence,
            'risk_class' => $riskClass,
            'task_constraints' => $taskConstraints,
            'dependency_hints' => $dependencyHints,
            'anti_proxy_clauses' => $antiProxyClauses,
        ];
        ksort($canonical);
        $draft['spec_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $draft;
    }

    /**
     * Best-effort impl → test pair: tries `tests/.../{Basename}Test.php` first, then any test file
     * whose name contains the impl basename.
     *
     * @param  list<string>  $tests
     */
    private function matchingTestFor(string $impl, array $tests): ?string
    {
        if ($tests === []) {
            return null;
        }
        $base = pathinfo($impl, PATHINFO_FILENAME);
        $expected = $base.'Test.php';
        foreach ($tests as $t) {
            if (basename($t) === $expected) {
                return $t;
            }
        }
        foreach ($tests as $t) {
            if (str_contains(basename($t), $base)) {
                return $t;
            }
        }

        return null;
    }
}

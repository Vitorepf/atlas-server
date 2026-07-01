<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\E2E;

/**
 * Pure end-to-end coverage gate. Verifies that EVERY canonical organ in Atlas Autonomous Engineering
 * Government has, simultaneously:
 *   - a task packet
 *   - an implementation surface (live code file)
 *   - a test evidence requirement
 *   - a runtime owner declaration (must be `atlas_native` in steady state)
 *
 * Emits FACTS-only lists: missing_organ, missing_gate, missing_receipt, autonomy_regression.
 * NEVER collapses coverage into a scalar score.
 */
final class AtlasSelfConstructionOrganContractCoverageGate
{
    public const SCHEMA = 'atlas.self_construction.organ_contract_coverage.v1';

    public const CANONICAL_ORGANS = [
        'cortex',
        'goal_value',
        'strategy',
        'architecture',
        'task_fabric',
        'maestro',
        'native_worker',
        'verification_court',
        'merge_governor',
        'knowledge_sync',
        'learning_transfer',
    ];

    public const REQUIRED_KEYS = [
        'task_packet',
        'implementation_surface',
        'test_evidence_requirement',
        'replenishment_contract',
        'runtime_owner',
        'runtime_integration_owner',
    ];

    public const READINESS_READY = 'ready';

    public const READINESS_BLOCKED = 'blocked';

    public const READINESS_PARTIAL = 'partial';

    /** a stale contract past this many days can no longer back a final autonomy claim. */
    private const STALE_CONTRACT_MAX_DAYS = 90;

    /**
     * @param  array<string, array<string,mixed>>  $registry  organ_id => {task_packet?, implementation_surface?,
     *                                                                      test_evidence_requirement?,
     *                                                                      replenishment_contract?,
     *                                                                      runtime_owner?,
     *                                                                      runtime_integration_owner?}
     * @return array<string,mixed>
     */
    public function evaluate(array $registry): array
    {
        $missingOrgan = [];
        $missingGate = [];
        $missingReceipt = [];
        $autonomyRegression = [];

        // Collect all implementation_surface values to detect duplicates.
        $surfaceIndex = []; // surface => [organ_id, ...]
        foreach ($registry as $organId => $row) {
            if (is_array($row) && isset($row['implementation_surface']) && $row['implementation_surface'] !== '' && $row['implementation_surface'] !== null) {
                $surfaceIndex[(string) $row['implementation_surface']][] = (string) $organId;
            }
        }
        $duplicateSurfaces = [];
        foreach ($surfaceIndex as $surface => $organs) {
            if (count($organs) > 1) {
                sort($organs);
                $duplicateSurfaces[] = $surface.':shared_by:'.implode(',', $organs);
            }
        }

        foreach (self::CANONICAL_ORGANS as $organ) {
            $row = $registry[$organ] ?? null;
            if (! is_array($row)) {
                $missingOrgan[] = $organ;

                continue;
            }
            foreach (self::REQUIRED_KEYS as $key) {
                if (! isset($row[$key]) || $row[$key] === '' || $row[$key] === null) {
                    match ($key) {
                        'task_packet' => $missingReceipt[] = $organ.':task_packet',
                        'implementation_surface' => $missingOrgan[] = $organ.':implementation_surface',
                        'test_evidence_requirement' => $missingGate[] = $organ.':test_evidence_requirement',
                        'replenishment_contract' => $missingReceipt[] = $organ.':replenishment_contract',
                        'runtime_owner' => $autonomyRegression[] = $organ.':runtime_owner_missing',
                        'runtime_integration_owner' => $autonomyRegression[] = $organ.':runtime_integration_owner_missing',
                        default => null,
                    };
                }
            }
            if (isset($row['runtime_owner']) && (string) $row['runtime_owner'] !== 'atlas_native') {
                $autonomyRegression[] = $organ.':runtime_owner_not_atlas_native:'.(string) $row['runtime_owner'];
            }
            if (isset($row['runtime_integration_owner']) && (string) $row['runtime_integration_owner'] !== 'atlas_native') {
                $autonomyRegression[] = $organ.':runtime_integration_owner_regression:'.(string) $row['runtime_integration_owner'];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'fully_covered' => $missingOrgan === [] && $missingGate === [] && $missingReceipt === [] && $autonomyRegression === [] && $duplicateSurfaces === [],
            'missing_organ' => array_values(array_unique($missingOrgan)),
            'missing_gate' => array_values(array_unique($missingGate)),
            'missing_receipt' => array_values(array_unique($missingReceipt)),
            'autonomy_regression' => array_values(array_unique($autonomyRegression)),
            'duplicate_surfaces' => array_values(array_unique($duplicateSurfaces)),
        ];
    }

    /**
     * E2E readiness verdict for a final autonomy claim — never lets partial coverage pass as ready.
     * Builds on evaluate()'s facts and adds two proofs evaluate() does not check: whether a declared
     * test_evidence_requirement is a real runnable gate (not a vague placeholder), and whether a
     * runtime_owner=atlas_native claim is backed by actual runtime proof rather than a bare
     * declaration. readiness_status is 'ready' ONLY when there are zero blockers AND zero weak tests.
     *
     * @param  array<string, array<string,mixed>>  $registry  same shape as evaluate(), plus optional
     *                                                          per-organ contract_verified_days_ago?:int,
     *                                                          runtime_proof_present?:bool
     * @return array{schema:string, critical_uncovered_organs:list<string>, weak_tests:list<string>, stale_contracts:list<string>, missing_runtime_proof:list<string>, readiness_status:string, blockers:list<string>}
     */
    public function evaluateReadiness(array $registry): array
    {
        $base = $this->evaluate($registry);

        $criticalUncoveredOrgans = array_values(array_filter(
            $base['missing_organ'],
            static fn (string $entry): bool => ! str_contains($entry, ':'),
        ));

        $weakTests = [];
        $staleContracts = [];
        $missingRuntimeProof = [];

        foreach (self::CANONICAL_ORGANS as $organ) {
            $row = $registry[$organ] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $testRequirement = (string) ($row['test_evidence_requirement'] ?? '');
            if ($testRequirement !== '' && preg_match('/\b(test|phpunit|artisan)\b/i', $testRequirement) !== 1) {
                $weakTests[] = $organ.':weak_test_evidence_requirement';
            }

            if (array_key_exists('contract_verified_days_ago', $row)) {
                $daysAgo = max(0, (int) $row['contract_verified_days_ago']);
                if ($daysAgo > self::STALE_CONTRACT_MAX_DAYS) {
                    $staleContracts[] = $organ.':stale_contract:'.$daysAgo.'_days';
                }
            }

            $ownerIsNative = (string) ($row['runtime_owner'] ?? '') === 'atlas_native';
            if ($ownerIsNative && ! (bool) ($row['runtime_proof_present'] ?? false)) {
                $missingRuntimeProof[] = $organ.':missing_runtime_proof';
            }
        }

        $blockers = array_values(array_unique(array_merge(
            $criticalUncoveredOrgans,
            $base['autonomy_regression'],
            $staleContracts,
            $missingRuntimeProof,
        )));
        sort($blockers, SORT_STRING);

        $readinessStatus = match (true) {
            $blockers !== [] => self::READINESS_BLOCKED,
            $weakTests !== [] => self::READINESS_PARTIAL,
            default => self::READINESS_READY,
        };

        return [
            'schema' => self::SCHEMA,
            'critical_uncovered_organs' => $criticalUncoveredOrgans,
            'weak_tests' => $weakTests,
            'stale_contracts' => $staleContracts,
            'missing_runtime_proof' => $missingRuntimeProof,
            'readiness_status' => $readinessStatus,
            'blockers' => $blockers,
        ];
    }
}

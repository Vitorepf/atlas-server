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
}

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

    public const REQUIRED_KEYS = ['task_packet', 'implementation_surface', 'test_evidence_requirement', 'runtime_owner'];

    /**
     * @param  array<string, array<string,mixed>>  $registry  organ_id => {task_packet?, implementation_surface?,
     *                                                                      test_evidence_requirement?, runtime_owner?}
     * @return array<string,mixed>
     */
    public function evaluate(array $registry): array
    {
        $missingOrgan = [];
        $missingGate = [];
        $missingReceipt = [];
        $autonomyRegression = [];

        foreach (self::CANONICAL_ORGANS as $organ) {
            $row = $registry[$organ] ?? null;
            if (! is_array($row)) {
                $missingOrgan[] = $organ;

                continue;
            }
            foreach (self::REQUIRED_KEYS as $key) {
                if (! isset($row[$key]) || $row[$key] === '' || $row[$key] === null) {
                    // Per-key missing surfaces routed to the right bucket.
                    match ($key) {
                        'task_packet' => $missingReceipt[] = $organ.':task_packet',
                        'implementation_surface' => $missingOrgan[] = $organ.':implementation_surface',
                        'test_evidence_requirement' => $missingGate[] = $organ.':test_evidence_requirement',
                        'runtime_owner' => $autonomyRegression[] = $organ.':runtime_owner_missing',
                        default => null,
                    };
                }
            }
            if (isset($row['runtime_owner']) && (string) $row['runtime_owner'] !== 'atlas_native') {
                $autonomyRegression[] = $organ.':runtime_owner_not_atlas_native:'.(string) $row['runtime_owner'];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'fully_covered' => $missingOrgan === [] && $missingGate === [] && $missingReceipt === [] && $autonomyRegression === [],
            'missing_organ' => array_values(array_unique($missingOrgan)),
            'missing_gate' => array_values(array_unique($missingGate)),
            'missing_receipt' => array_values(array_unique($missingReceipt)),
            'autonomy_regression' => array_values(array_unique($autonomyRegression)),
        ];
    }
}

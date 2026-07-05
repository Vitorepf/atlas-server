<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure gate that determines which autonomy capabilities can be ported across
 * project lanes without copying Atlas-specific assumptions.
 *
 * Rules:
 *   - Generic capabilities (task_serving, evidence_collection, etc.) → PASS (portable)
 *   - Atlas-specific governance assumptions (atlas_native, self_programming, etc.) → REQUIRE_ADAPTATION
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasProjectLaneAutonomyCapabilityPortabilityGate
{
    public const SCHEMA = 'atlas.project_lane.autonomy_capability_portability_gate.v1';

    public const VERDICT_PASS = 'pass';
    public const VERDICT_REQUIRE_ADAPTATION = 'require_adaptation';

    private const ATLAS_SPECIFIC_MARKERS = [
        'atlas_native', 'atlas_server', 'self_programming',
        'atlas_task_serving', 'atlas_evidence_ledger',
        'atlas_self_construction', 'atlas_maestro',
        'atlas_brain', 'atlas_cortex',
    ];

    private const GENERIC_CAPABILITIES = [
        'task_serving', 'evidence_collection', 'test_execution',
        'code_edit', 'refactor', 'documentation',
        'debugging', 'deployment', 'monitoring',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function gate(array $input): array
    {
        $capability = strtolower(trim((string) ($input['capability'] ?? '')));
        $sourceLane = (string) ($input['source_lane'] ?? '');
        $targetLane = (string) ($input['target_lane'] ?? '');

        $isAtlasSpecific = false;
        foreach (self::ATLAS_SPECIFIC_MARKERS as $marker) {
            if (str_contains($capability, $marker)) {
                $isAtlasSpecific = true;
                break;
            }
        }

        $isGeneric = in_array($capability, self::GENERIC_CAPABILITIES, true);

        $verdict = match (true) {
            $isAtlasSpecific => self::VERDICT_REQUIRE_ADAPTATION,
            $isGeneric => self::VERDICT_PASS,
            default => self::VERDICT_REQUIRE_ADAPTATION,
        };

        $reasons = [];
        $adaptationTasks = [];

        if ($verdict === self::VERDICT_REQUIRE_ADAPTATION) {
            $reasons[] = 'atlas_specific_assumptions_require_adaptation';
            $adaptationTasks[] = 'replace_atlas_native_with_lane_native_owner';
            $adaptationTasks[] = 'replace_atlas_specific_governance_with_lane_governance';
        } else {
            $reasons[] = 'generic_capability_portable';
        }

        return [
            'schema_version' => self::SCHEMA,
            'capability' => $capability,
            'source_lane' => $sourceLane,
            'target_lane' => $targetLane,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'is_atlas_specific' => $isAtlasSpecific,
            'is_generic' => $isGeneric,
            'adaptation_tasks' => $adaptationTasks,
        ];
    }
}

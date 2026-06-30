<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier: classifies each ExternalBrain organ as integrated, orphaned,
 * or intentionally_standalone.
 *
 * Classification (first match wins):
 *   integrated             → has at least one consumer in flow_usage OR control_plane_exposure=true
 *   intentionally_standalone → no consumer, no control-plane, but standalone_justifications entry present
 *   orphaned               → none of the above (unconnected and unjustified)
 *
 * An organ is flagged as a problematic orphan when has_tests=true AND has_implementation=true:
 * it passes tests but never affects origination.
 */
final class AtlasExternalBrainOrganIntegrationVerifier
{
    public const SCHEMA = 'atlas.external_brain.organ_integration_verifier.v1';

    public const STATUS_INTEGRATED = 'integrated';

    public const STATUS_ORPHANED = 'orphaned';

    public const STATUS_INTENTIONALLY_STANDALONE = 'intentionally_standalone';

    /**
     * @param  array<string,mixed>  $input  organ_inventory, flow_usage, control_plane_exposure, standalone_justifications
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $inventory = is_array($input['organ_inventory'] ?? null) ? $input['organ_inventory'] : [];
        $flowUsage = is_array($input['flow_usage'] ?? null) ? $input['flow_usage'] : [];
        $controlPlane = is_array($input['control_plane_exposure'] ?? null) ? $input['control_plane_exposure'] : [];
        $justifications = is_array($input['standalone_justifications'] ?? null) ? $input['standalone_justifications'] : [];

        $results = [];
        $integratedIds = [];
        $orphanedIds = [];
        $standaloneIds = [];

        foreach ($inventory as $organ) {
            $id = (string) ($organ['organ_id'] ?? '');
            $hasTests = (bool) ($organ['has_tests'] ?? false);
            $hasImpl = (bool) ($organ['has_implementation'] ?? false);

            $consumers = is_array($flowUsage[$id] ?? null)
                ? array_values(array_map('strval', $flowUsage[$id]))
                : [];
            $hasControlPlane = (bool) ($controlPlane[$id] ?? false);
            $standaloneReason = trim((string) ($justifications[$id] ?? ''));

            if ($consumers !== [] || $hasControlPlane) {
                $status = self::STATUS_INTEGRATED;
                $integratedIds[] = $id;
            } elseif ($standaloneReason !== '') {
                $status = self::STATUS_INTENTIONALLY_STANDALONE;
                $standaloneIds[] = $id;
            } else {
                $status = self::STATUS_ORPHANED;
                $orphanedIds[] = $id;
            }

            $results[] = [
                'organ_id' => $id,
                'status' => $status,
                'has_tests' => $hasTests,
                'has_implementation' => $hasImpl,
                'capability_island' => $status === self::STATUS_ORPHANED && $hasTests && $hasImpl,
                'consumers' => $consumers,
                'control_plane_exposed' => $hasControlPlane,
                'standalone_reason' => $standaloneReason !== '' ? $standaloneReason : null,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'total_organs' => count($inventory),
            'results' => $results,
            'integrated_ids' => $integratedIds,
            'orphaned_ids' => $orphanedIds,
            'intentionally_standalone_ids' => $standaloneIds,
            'has_orphans' => $orphanedIds !== [],
        ];
    }
}

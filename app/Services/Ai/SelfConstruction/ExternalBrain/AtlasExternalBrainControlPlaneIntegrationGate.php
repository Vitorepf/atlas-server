<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Requires important ExternalBrain organs to appear in the control-plane snapshot, the final
 * readiness map, or an explicit standalone registry before they can be counted as delivered.
 *
 * DELIVERY RULE (evaluated in priority order):
 *   1. organ is_important = false      → always count_as_delivered (non-critical organs pass freely)
 *   2. control_plane_exposure = true   → count_as_delivered (organ is in the control-plane snapshot)
 *   3. readiness_map_exposure = true   → count_as_delivered (organ appears in the final readiness map)
 *   4. standalone_reason AND evidence_floor both non-empty
 *                                      → count_as_delivered (explicit standalone exception granted)
 *   5. otherwise                       → NOT count_as_delivered (important, unexposed, no standalone)
 *
 * INPUT:
 *   organ_id:                string
 *   is_important:            bool   (default false)
 *   control_plane_exposure:  bool   (default false)
 *   readiness_map_exposure:  bool   (default false)
 *   consumer_links:          list<string>  (default []) — informational, used in reason output
 *   standalone_reason:       string  (default '') — explicit justification for standalone delivery
 *   evidence_floor:          string  (default '') — minimum evidence required for standalone
 *
 * OUTPUT:
 *   { schema, organ_id, count_as_delivered:bool, exposure_path:string, reasons:list<string> }
 *
 *   exposure_path: 'control_plane' | 'readiness_map' | 'standalone' | 'not_important' | 'none'
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainControlPlaneIntegrationGate
{
    public const SCHEMA = 'atlas.external_brain.control_plane_integration_gate.v1';

    public const PATH_CONTROL_PLANE  = 'control_plane';
    public const PATH_READINESS_MAP  = 'readiness_map';
    public const PATH_STANDALONE     = 'standalone';
    public const PATH_NOT_IMPORTANT  = 'not_important';
    public const PATH_NONE           = 'none';

    /**
     * @param  array<string,mixed>  $organ
     * @return array<string,mixed>
     */
    public function evaluate(array $organ): array
    {
        $organId              = (string) ($organ['organ_id'] ?? '');
        $isImportant          = (bool) ($organ['is_important'] ?? false);
        $controlPlane         = (bool) ($organ['control_plane_exposure'] ?? false);
        $readinessMap         = (bool) ($organ['readiness_map_exposure'] ?? false);
        $consumerLinks        = array_values(array_map('strval', (array) ($organ['consumer_links'] ?? [])));
        $standaloneReason     = trim((string) ($organ['standalone_reason'] ?? ''));
        $evidenceFloor        = trim((string) ($organ['evidence_floor'] ?? ''));

        $reasons = [];

        // Rule 1: non-important → free pass.
        if (! $isImportant) {
            $reasons[] = 'organ_not_important:no_exposure_required';

            return $this->result($organId, true, self::PATH_NOT_IMPORTANT, $reasons);
        }

        // Rule 2: exposed via control plane.
        if ($controlPlane) {
            $reasons[] = 'exposed_via_control_plane_snapshot';
            if ($consumerLinks !== []) {
                $reasons[] = sprintf('consumer_links:%s', implode(',', $consumerLinks));
            }

            return $this->result($organId, true, self::PATH_CONTROL_PLANE, $reasons);
        }

        // Rule 3: exposed via final readiness map.
        if ($readinessMap) {
            $reasons[] = 'exposed_via_final_readiness_map';
            if ($consumerLinks !== []) {
                $reasons[] = sprintf('consumer_links:%s', implode(',', $consumerLinks));
            }

            return $this->result($organId, true, self::PATH_READINESS_MAP, $reasons);
        }

        // Rule 4: explicit standalone exception with both fields present.
        if ($standaloneReason !== '' && $evidenceFloor !== '') {
            $reasons[] = 'standalone_exception_granted:reason='.$standaloneReason;
            $reasons[] = 'evidence_floor='.$evidenceFloor;

            return $this->result($organId, true, self::PATH_STANDALONE, $reasons);
        }

        // Rule 5: important, unexposed, no valid standalone.
        $reasons[] = 'important_organ_has_no_control_plane_exposure';
        $reasons[] = 'important_organ_has_no_readiness_map_exposure';

        if ($standaloneReason === '' && $evidenceFloor === '') {
            $reasons[] = 'standalone_exception_missing:standalone_reason_and_evidence_floor_required';
        } elseif ($standaloneReason === '') {
            $reasons[] = 'standalone_exception_incomplete:missing_standalone_reason';
        } else {
            $reasons[] = 'standalone_exception_incomplete:missing_evidence_floor';
        }

        return $this->result($organId, false, self::PATH_NONE, $reasons);
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function result(string $organId, bool $delivered, string $exposurePath, array $reasons): array
    {
        return [
            'schema'             => self::SCHEMA,
            'organ_id'           => $organId,
            'count_as_delivered' => $delivered,
            'exposure_path'      => $exposurePath,
            'reasons'            => array_values($reasons),
        ];
    }
}

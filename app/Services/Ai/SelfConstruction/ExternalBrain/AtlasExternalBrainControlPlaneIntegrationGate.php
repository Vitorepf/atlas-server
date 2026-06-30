<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Requires important ExternalBrain organs to appear in the control-plane snapshot, the final
 * readiness map, or an explicit standalone registry before they can be counted as delivered.
 *
 * DELIVERY RULE (evaluated in priority order):
 *   1. organ is_important = false      → always count_as_delivered (not_required)
 *   2. is_read_only_helper = true AND control_plane_exposure = false
 *                                      → NOT count_as_delivered (read_only_helper_blocked)
 *                                        AC3: read-only helpers cannot claim final capability
 *                                        without downstream control-plane use.
 *   3. control_plane_exposure = true   → count_as_delivered (control_plane_wired)
 *   4. readiness_map_exposure = true   → count_as_delivered (readiness_map_wired)
 *   5. standalone_reason AND evidence_floor AND consumer_links AND expiry all non-empty
 *                                      → count_as_delivered (standalone_wired)
 *   6. otherwise                       → NOT count_as_delivered (not_integrated)
 *
 * INPUT:
 *   organ_id:                    string
 *   is_important:                bool   (default false)
 *   is_read_only_helper:         bool   (default false) — pure readers cannot self-certify
 *   control_plane_exposure:      bool   (default false)
 *   readiness_map_exposure:      bool   (default false)
 *   consumer_links:              list<string>  (default [])
 *   decision_effect:             string (default '') — how organ affects a control-plane decision
 *   standalone_reason:           string (default '')
 *   evidence_floor:              string (default '')
 *   expiry_or_review_condition:  string (default '')
 *
 * OUTPUT:
 *   { schema, organ_id, count_as_delivered:bool, exposure_path:string,
 *     integration_status:string, decision_path:string, consumer:string,
 *     evidence_requirements:list<string>, reasons:list<string>,
 *     integration_blockers:list<string> }
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainControlPlaneIntegrationGate
{
    public const SCHEMA = 'atlas.external_brain.control_plane_integration_gate.v1';

    public const PATH_CONTROL_PLANE = 'control_plane';
    public const PATH_READINESS_MAP = 'readiness_map';
    public const PATH_STANDALONE    = 'standalone';
    public const PATH_NOT_IMPORTANT = 'not_important';
    public const PATH_NONE          = 'none';

    public const STATUS_NOT_REQUIRED             = 'not_required';
    public const STATUS_CONTROL_PLANE_WIRED      = 'control_plane_wired';
    public const STATUS_READINESS_MAP_WIRED      = 'readiness_map_wired';
    public const STATUS_STANDALONE_WIRED         = 'standalone_wired';
    public const STATUS_READ_ONLY_HELPER_BLOCKED = 'read_only_helper_blocked';
    public const STATUS_NOT_INTEGRATED           = 'not_integrated';

    /**
     * @param  array<string,mixed>  $organ
     * @return array<string,mixed>
     */
    public function evaluate(array $organ): array
    {
        $organId             = (string) ($organ['organ_id'] ?? '');
        $isImportant         = (bool) ($organ['is_important'] ?? false);
        $isReadOnlyHelper    = (bool) ($organ['is_read_only_helper'] ?? false);
        $controlPlane        = (bool) ($organ['control_plane_exposure'] ?? false);
        $readinessMap        = (bool) ($organ['readiness_map_exposure'] ?? false);
        $consumerLinks       = array_values(array_filter(array_map('strval', (array) ($organ['consumer_links'] ?? []))));
        $decisionEffect      = trim((string) ($organ['decision_effect'] ?? ''));
        $standaloneReason    = trim((string) ($organ['standalone_reason'] ?? ''));
        $evidenceFloor       = trim((string) ($organ['evidence_floor'] ?? ''));
        $expiry              = trim((string) ($organ['expiry_or_review_condition'] ?? ''));

        $reasons = [];

        // Rule 1: non-important → free pass.
        if (! $isImportant) {
            $reasons[] = 'organ_not_important:no_exposure_required';

            return $this->result($organId, true, self::PATH_NOT_IMPORTANT, self::STATUS_NOT_REQUIRED,
                '', '', [], $reasons);
        }

        // Rule 2 (AC3): read-only helpers blocked without control-plane wiring.
        if ($isReadOnlyHelper && ! $controlPlane) {
            $reasons[] = 'read_only_helper_cannot_claim_final_capability_without_control_plane';
            $reasons[] = 'no_downstream_control_plane_use_detected';

            return $this->result($organId, false, self::PATH_NONE, self::STATUS_READ_ONLY_HELPER_BLOCKED,
                '', '', ['missing_control_plane_wiring_for_read_only_helper'], $reasons,
                ['read_only_helper_requires_control_plane_exposure']);
        }

        // Rule 3: control-plane wired.
        if ($controlPlane) {
            $reasons[] = 'exposed_via_control_plane_snapshot';
            if ($decisionEffect !== '') {
                $reasons[] = 'decision_effect:'.$decisionEffect;
            }
            $consumer             = $consumerLinks[0] ?? '';
            $evidenceRequirements = $evidenceFloor !== [] ? [$evidenceFloor] : [];

            if ($consumerLinks !== []) {
                $reasons[] = 'consumer_links:'.implode(',', $consumerLinks);
            }

            return $this->result($organId, true, self::PATH_CONTROL_PLANE, self::STATUS_CONTROL_PLANE_WIRED,
                $decisionEffect, $consumer, $evidenceRequirements, $reasons);
        }

        // Rule 4: readiness-map wired.
        if ($readinessMap) {
            $reasons[] = 'exposed_via_final_readiness_map';
            $consumer             = $consumerLinks[0] ?? '';
            $evidenceRequirements = $evidenceFloor !== '' ? [$evidenceFloor] : [];

            if ($consumerLinks !== []) {
                $reasons[] = 'consumer_links:'.implode(',', $consumerLinks);
            }

            return $this->result($organId, true, self::PATH_READINESS_MAP, self::STATUS_READINESS_MAP_WIRED,
                '', $consumer, $evidenceRequirements, $reasons);
        }

        // Rule 5: explicit standalone exception — all 4 fields required (fail-closed).
        $standaloneComplete = $standaloneReason !== '' && $evidenceFloor !== ''
            && $consumerLinks !== [] && $expiry !== '';

        if ($standaloneComplete) {
            $reasons[] = 'standalone_exception_granted:reason='.$standaloneReason;
            $reasons[] = 'evidence_floor='.$evidenceFloor;
            $reasons[] = 'consumer_links:'.implode(',', $consumerLinks);
            $reasons[] = 'expiry_or_review_condition='.$expiry;

            return $this->result($organId, true, self::PATH_STANDALONE, self::STATUS_STANDALONE_WIRED,
                '', $consumerLinks[0], [$evidenceFloor], $reasons);
        }

        // Rule 6: not_integrated.
        $reasons[] = 'important_organ_has_no_control_plane_exposure';
        $reasons[] = 'important_organ_has_no_readiness_map_exposure';

        $integrationBlockers = ['no_control_plane_exposure', 'no_readiness_map_exposure'];

        $anyStandaloneField = $standaloneReason !== '' || $evidenceFloor !== ''
            || $consumerLinks !== [] || $expiry !== '';

        if (! $anyStandaloneField) {
            $reasons[] = 'standalone_exception_missing:all_four_fields_required';
            array_push($integrationBlockers,
                'missing_standalone_reason', 'missing_evidence_floor',
                'missing_consumer_links', 'missing_expiry_or_review_condition');
        } else {
            if ($standaloneReason === '') {
                $reasons[]             = 'standalone_exception_incomplete:missing_standalone_reason';
                $integrationBlockers[] = 'missing_standalone_reason';
            }
            if ($evidenceFloor === '') {
                $reasons[]             = 'standalone_exception_incomplete:missing_evidence_floor';
                $integrationBlockers[] = 'missing_evidence_floor';
            }
            if ($consumerLinks === []) {
                $reasons[]             = 'standalone_exception_incomplete:missing_consumer_links';
                $integrationBlockers[] = 'missing_consumer_links';
            }
            if ($expiry === '') {
                $reasons[]             = 'standalone_exception_incomplete:missing_expiry_or_review_condition';
                $integrationBlockers[] = 'missing_expiry_or_review_condition';
            }
        }

        return $this->result($organId, false, self::PATH_NONE, self::STATUS_NOT_INTEGRATED,
            '', '', [], $reasons, $integrationBlockers);
    }

    /**
     * @param  list<string>  $evidenceRequirements
     * @param  list<string>  $reasons
     * @param  list<string>  $integrationBlockers
     * @return array<string,mixed>
     */
    private function result(
        string $organId,
        bool $delivered,
        string $exposurePath,
        string $integrationStatus,
        string $decisionPath,
        string $consumer,
        array $evidenceRequirements,
        array $reasons,
        array $integrationBlockers = [],
    ): array {
        return [
            'schema'                => self::SCHEMA,
            'organ_id'              => $organId,
            'count_as_delivered'    => $delivered,
            'exposure_path'         => $exposurePath,
            'integration_status'    => $integrationStatus,
            'decision_path'         => $decisionPath,
            'consumer'              => $consumer,
            'evidence_requirements' => array_values($evidenceRequirements),
            'reasons'               => array_values($reasons),
            'integration_blockers'  => array_values($integrationBlockers),
        ];
    }
}

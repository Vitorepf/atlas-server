<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure integration mapper. Classifies ExternalBrain capability services by how
 * well they are wired into the self-construction system.
 *
 * Input facts:
 *   capabilities — list of {id, is_implemented, is_wired, integration_points?, connected_to?}.
 *     is_implemented    — code artifact exists.
 *     is_wired          — at least one caller invokes this capability.
 *     integration_points — list of system points this capability should plug into.
 *     connected_to       — list of system points it is actually connected to.
 *
 * AC2 — Integration status:
 *   fully_integrated  — is_implemented AND is_wired AND no missing integration_points.
 *   integration_debt  — is_implemented BUT (!is_wired OR missing integration_points).
 *   not_implemented   — !is_implemented (regardless of wiring claims).
 *
 * Coverage score (0–1):
 *   When integration_points is empty: 1.0 if fully_integrated, 0.0 otherwise.
 *   Otherwise: connected_to ∩ integration_points / total integration_points.
 *
 * AC4 outputs: capability_map, integration_debt_items, fulfilled_capabilities,
 *   debt_summary.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainCapabilityIntegrationMap
{
    public const SCHEMA = 'atlas.external_brain.capability_integration_map.v1';

    private const STATUS_FULFILLED = 'fully_integrated';
    private const STATUS_DEBT      = 'integration_debt';
    private const STATUS_MISSING   = 'not_implemented';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function map(array $facts): array
    {
        $capabilities = is_array($facts['capabilities'] ?? null) ? $facts['capabilities'] : [];

        $capabilityMap       = [];
        $debtItems           = [];
        $fulfilled           = [];
        $totalImplemented    = 0;
        $totalWired          = 0;
        $totalDebt           = 0;
        $totalNotImplemented = 0;

        foreach ($capabilities as $cap) {
            $id              = (string) ($cap['id'] ?? '');
            $isImplemented   = (bool) ($cap['is_implemented'] ?? false);
            $isWired         = (bool) ($cap['is_wired']       ?? false);
            $integPoints     = is_array($cap['integration_points'] ?? null) ? $cap['integration_points'] : [];
            $connectedTo     = is_array($cap['connected_to']       ?? null) ? $cap['connected_to']       : [];

            // Missing integration points = required points not in connected_to.
            $missingConnections = array_values(array_diff($integPoints, $connectedTo));

            // AC2: classify.
            if (! $isImplemented) {
                $status = self::STATUS_MISSING;
                $totalNotImplemented++;
            } elseif (! $isWired || ! empty($missingConnections)) {
                $status = self::STATUS_DEBT;
                $totalImplemented++;
                $totalDebt++;
                if ($isWired) {
                    $totalWired++;
                }
                $debtItems[] = ['capability_id' => $id, 'missing_connections' => $missingConnections];
            } else {
                $status = self::STATUS_FULFILLED;
                $totalImplemented++;
                $totalWired++;
                $fulfilled[] = $id;
            }

            // Coverage score.
            $coverageScore = $this->coverage($status, $integPoints, $connectedTo);

            $capabilityMap[] = [
                'capability_id'      => $id,
                'integration_status' => $status,
                'missing_connections' => $missingConnections,
                'coverage_score'     => $coverageScore,
            ];
        }

        return [
            'schema_version'          => self::SCHEMA,
            'capability_map'          => $capabilityMap,
            'integration_debt_items'  => $debtItems,
            'fulfilled_capabilities'  => $fulfilled,
            'debt_summary'            => [
                'total'              => count($capabilities),
                'implemented'        => $totalImplemented,
                'wired'              => $totalWired,
                'integration_debt'   => $totalDebt,
                'not_implemented'    => $totalNotImplemented,
            ],
        ];
    }

    private function coverage(string $status, array $integPoints, array $connectedTo): float
    {
        if (empty($integPoints)) {
            return $status === self::STATUS_FULFILLED ? 1.0 : 0.0;
        }
        $covered = count(array_intersect($integPoints, $connectedTo));

        return round($covered / count($integPoints), 4);
    }
}

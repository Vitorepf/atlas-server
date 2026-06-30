<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure integration mapper. Classifies ExternalBrain capability services by how
 * well they are wired into the self-construction system.
 *
 * Input facts:
 *   capabilities — list of {id, is_implemented, is_wired, integration_points?,
 *     connected_to?, consumer_count?, has_contract?, control_plane_exists?}.
 *
 * AC2 — Integration status priority (first match):
 *   not_implemented   — !is_implemented.
 *   contract_missing  — is_implemented AND has_contract===false (field explicitly present).
 *   dormant_implemented — is_implemented AND consumer_count===0 (explicitly provided) AND
 *                         !is_wired AND no missing integration_points.
 *   integration_debt  — is_implemented AND (!is_wired OR missing integration_points).
 *   fully_integrated  — else.
 *
 * AC3 — Coverage score (0–1):
 *   coverage_score   — connected_to ∩ integration_points / total (unchanged).
 *   wiring_coverage  — composite: 0.6×coverage + 0.2×consumer_factor + 0.2×control_factor.
 *     consumer_factor  = min(1.0, consumer_count / CONSUMER_CEILING) when consumer_count provided.
 *     control_factor   = 1.0 if control_plane_exists, else 0.0.
 *
 * AC4 — integration_debt_items each carry next_wiring_actions (list of strings).
 *   debt_summary extended with dormant_implemented and contract_missing counts.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainCapabilityIntegrationMap
{
    public const SCHEMA = 'atlas.external_brain.capability_integration_map.v1';

    private const STATUS_FULFILLED        = 'fully_integrated';
    private const STATUS_DEBT             = 'integration_debt';
    private const STATUS_DORMANT          = 'dormant_implemented';
    private const STATUS_CONTRACT_MISSING = 'contract_missing';
    private const STATUS_MISSING          = 'not_implemented';

    // AC3: consumer_count at or above this ceiling yields max consumer factor (1.0).
    private const CONSUMER_CEILING = 5;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function map(array $facts): array
    {
        $capabilities = is_array($facts['capabilities'] ?? null) ? $facts['capabilities'] : [];

        $capabilityMap        = [];
        $debtItems            = [];
        $fulfilled            = [];
        $totalImplemented     = 0;
        $totalWired           = 0;
        $totalDebt            = 0;
        $totalDormant         = 0;
        $totalContractMissing = 0;
        $totalNotImplemented  = 0;

        foreach ($capabilities as $cap) {
            $id                 = (string) ($cap['id'] ?? '');
            $isImplemented      = (bool)   ($cap['is_implemented'] ?? false);
            $isWired            = (bool)   ($cap['is_wired']       ?? false);
            $integPoints        = is_array($cap['integration_points'] ?? null) ? $cap['integration_points'] : [];
            $connectedTo        = is_array($cap['connected_to']       ?? null) ? $cap['connected_to']       : [];
            $consumerCount      = array_key_exists('consumer_count', $cap) ? (int) $cap['consumer_count'] : null;
            $hasContract        = ! array_key_exists('has_contract', $cap) || (bool) $cap['has_contract'];
            $controlPlaneExists = (bool) ($cap['control_plane_exists'] ?? false);

            $missingConnections = array_values(array_diff($integPoints, $connectedTo));

            // AC2: classify (first match wins).
            if (! $isImplemented) {
                $status = self::STATUS_MISSING;
                $totalNotImplemented++;
            } elseif (! $hasContract) {
                $status = self::STATUS_CONTRACT_MISSING;
                $totalImplemented++;
                $totalContractMissing++;
            } elseif ($consumerCount === 0 && ! $isWired && empty($missingConnections)) {
                $status = self::STATUS_DORMANT;
                $totalImplemented++;
                $totalDormant++;
            } elseif (! $isWired || ! empty($missingConnections)) {
                $status = self::STATUS_DEBT;
                $totalImplemented++;
                $totalDebt++;
                if ($isWired) {
                    $totalWired++;
                }
                $debtItems[] = [
                    'capability_id'       => $id,
                    'missing_connections' => $missingConnections,
                    'next_wiring_actions' => $this->nextWiringActions($isWired, $missingConnections, $consumerCount),
                ];
            } else {
                $status = self::STATUS_FULFILLED;
                $totalImplemented++;
                $totalWired++;
                $fulfilled[] = $id;
            }

            // AC3: coverage_score (unchanged formula) + wiring_coverage composite.
            $coverageScore  = $this->coverage($status, $integPoints, $connectedTo);
            $consumerFactor = ($consumerCount !== null && $consumerCount > 0)
                ? min(1.0, $consumerCount / self::CONSUMER_CEILING)
                : 0.0;
            $controlFactor  = $controlPlaneExists ? 1.0 : 0.0;
            $wiringCoverage = round($coverageScore * 0.6 + $consumerFactor * 0.2 + $controlFactor * 0.2, 4);

            $capabilityMap[] = [
                'capability_id'       => $id,
                'integration_status'  => $status,
                'missing_connections' => $missingConnections,
                'coverage_score'      => $coverageScore,
                'wiring_coverage'     => $wiringCoverage,
                'consumer_count'      => $consumerCount,
                'control_plane_exists' => $controlPlaneExists,
            ];
        }

        return [
            'schema_version'          => self::SCHEMA,
            'capability_map'          => $capabilityMap,
            'integration_debt_items'  => $debtItems,
            'fulfilled_capabilities'  => $fulfilled,
            'debt_summary'            => [
                'total'               => count($capabilities),
                'implemented'         => $totalImplemented,
                'wired'               => $totalWired,
                'integration_debt'    => $totalDebt,
                'dormant_implemented' => $totalDormant,
                'contract_missing'    => $totalContractMissing,
                'not_implemented'     => $totalNotImplemented,
            ],
        ];
    }

    /**
     * @param  string[]  $missingConnections
     * @return string[]
     */
    private function nextWiringActions(bool $isWired, array $missingConnections, ?int $consumerCount): array
    {
        $actions = [];
        if (! $isWired) {
            $actions[] = 'wire_capability: add at least one active caller';
        }
        foreach ($missingConnections as $point) {
            $actions[] = "connect_to:{$point}";
        }
        if ($consumerCount !== null && $consumerCount === 0) {
            $actions[] = 'add_consumers: activate dormant capability by wiring consumers';
        }

        return $actions;
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

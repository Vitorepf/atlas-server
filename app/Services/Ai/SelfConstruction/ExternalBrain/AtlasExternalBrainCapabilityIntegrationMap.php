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

        $capabilities    = array_values($capabilities); // ensure 0-indexed for circuit pass
        // Circuit/isolation analysis only makes sense for capabilities that actually exist —
        // a not_implemented organ has no integration to evaluate and must never be recommended
        // for "retire" (that's a build gap, not integration debt).
        $implementedCapabilities = array_values(array_filter(
            $capabilities,
            static fn (array $cap): bool => (bool) ($cap['is_implemented'] ?? false),
        ));
        $circuits        = $this->buildCircuits($implementedCapabilities, $capabilityMap);
        $isolatedOrgans  = $this->findIsolatedOrgans($implementedCapabilities);
        $recommendations = $this->buildRecommendations($circuits, $isolatedOrgans);

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
            'circuits'                => $circuits,
            'isolated_organs'         => $isolatedOrgans,
            'circuit_recommendations' => $recommendations,
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

    /**
     * Group organs into named circuits. Circuit ID = explicit `circuit` field or first integration_point.
     * Organs with no integration_points and no circuit field are excluded (they are isolated).
     *
     * @param  array<int,array<string,mixed>>  $capabilities
     * @param  array<int,array<string,mixed>>  $capabilityMap
     * @return list<array{circuit_id:string, member_organ_ids:list<string>, upstream_inputs:list<string>, downstream_consumers:int, missing_edges:list<string>}>
     */
    private function buildCircuits(array $capabilities, array $capabilityMap): array
    {
        $buckets = [];
        $mapById = array_column($capabilityMap, null, 'capability_id');

        foreach ($capabilities as $cap) {
            $id          = (string) ($cap['id'] ?? '');
            $integPoints = is_array($cap['integration_points'] ?? null) ? $cap['integration_points'] : [];
            $connectedTo = is_array($cap['connected_to']       ?? null) ? $cap['connected_to']       : [];
            $circuitName = (string) ($cap['circuit']           ?? '');

            if ($circuitName === '' && $integPoints === []) {
                continue; // isolated — handled separately
            }
            if ($circuitName === '') {
                $circuitName = $integPoints[0];
            }

            if (! array_key_exists($circuitName, $buckets)) {
                $buckets[$circuitName] = [
                    'circuit_id'           => $circuitName,
                    'member_organ_ids'     => [],
                    'upstream_inputs'      => [],
                    'downstream_consumers' => 0,
                    'missing_edges'        => [],
                ];
            }

            $buckets[$circuitName]['member_organ_ids'][] = $id;

            foreach ($connectedTo as $input) {
                if (! in_array($input, $buckets[$circuitName]['upstream_inputs'], true)) {
                    $buckets[$circuitName]['upstream_inputs'][] = $input;
                }
            }

            $consumerCount = array_key_exists('consumer_count', $cap) ? (int) $cap['consumer_count'] : 0;
            $buckets[$circuitName]['downstream_consumers'] += $consumerCount;

            $mapEntry = $mapById[$id] ?? [];
            foreach ((array) ($mapEntry['missing_connections'] ?? []) as $edge) {
                if (! in_array($edge, $buckets[$circuitName]['missing_edges'], true)) {
                    $buckets[$circuitName]['missing_edges'][] = $edge;
                }
            }
        }

        return array_values($buckets);
    }

    /**
     * Find organs that have no integration_points, no connected_to, no consumers, and no circuit field.
     *
     * @param  array<int,array<string,mixed>>  $capabilities
     * @return list<string>
     */
    private function findIsolatedOrgans(array $capabilities): array
    {
        $isolated = [];
        foreach ($capabilities as $cap) {
            $integPoints   = is_array($cap['integration_points'] ?? null) ? $cap['integration_points'] : [];
            $connectedTo   = is_array($cap['connected_to']       ?? null) ? $cap['connected_to']       : [];
            $consumerCount = array_key_exists('consumer_count', $cap) ? (int) $cap['consumer_count'] : null;
            $circuit       = (string) ($cap['circuit']           ?? '');

            if ($integPoints === [] && $connectedTo === [] && ($consumerCount === null || $consumerCount === 0) && $circuit === '') {
                $isolated[] = (string) ($cap['id'] ?? '');
            }
        }
        return $isolated;
    }

    /**
     * Build recommendations from circuit analysis.
     * Rules:
     *   connect  — circuit has named missing_edges (specific targets → high_leverage:true)
     *   merge    — circuit has ≥2 members with zero downstream_consumers (redundancy → high_leverage:true)
     *   retire   — isolated organ with no integration path (no targets → high_leverage:false)
     *
     * AC4: wrapper-only recommendations (no named targets) must NOT be high_leverage.
     *
     * @param  list<array<string,mixed>>  $circuits
     * @param  list<string>               $isolatedOrgans
     * @return list<array{action:string, rationale:string, high_leverage:bool}>
     */
    private function buildRecommendations(array $circuits, array $isolatedOrgans): array
    {
        $recommendations = [];

        foreach ($isolatedOrgans as $organId) {
            $recommendations[] = [
                'organ_id'      => $organId,
                'action'        => 'retire',
                'rationale'     => 'organ has no integration_points, no connections, and no consumers; it adds no value',
                'high_leverage' => false,  // no named integration targets → wrapper-only ceiling
            ];
        }

        foreach ($circuits as $circuit) {
            if ($circuit['missing_edges'] !== []) {
                $edges = implode(', ', $circuit['missing_edges']);
                $recommendations[] = [
                    'circuit_id'    => $circuit['circuit_id'],
                    'action'        => 'connect',
                    'rationale'     => "circuit missing named edges: {$edges}; connecting increases autonomy",
                    'high_leverage' => true,  // specific targets named → real integration
                ];
            }

            if (count($circuit['member_organ_ids']) >= 2 && $circuit['downstream_consumers'] === 0) {
                $memberCount = count($circuit['member_organ_ids']);
                $recommendations[] = [
                    'circuit_id'    => $circuit['circuit_id'],
                    'action'        => 'merge',
                    'rationale'     => "circuit has {$memberCount} organs with zero downstream consumers; merging eliminates redundancy without adding wrapper classes",
                    'high_leverage' => true,
                ];
            }
        }

        return $recommendations;
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

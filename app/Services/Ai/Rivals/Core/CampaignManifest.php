<?php

namespace App\Services\Ai\Rivals\Core;

use InvalidArgumentException;

/**
 * Build campaign manifests for a mode that span stack/risk/duration over rotating,
 * private unit sets. Units must be disjoint across campaigns (no unit proves capability
 * twice). The emitted shape is exactly what WorldTrialReadiness consumes; this class
 * only assembles manifests — it never issues a claim. Pure over arrays.
 */
final class CampaignManifest
{
    public const SCHEMA = 'atlas.rivals2.campaign_manifest.v1';

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function campaign(array $spec): array
    {
        $unitIds = array_values(array_unique(array_map('strval', (array) ($spec['unit_ids'] ?? []))));
        $critical = [];
        foreach ((array) ($spec['critical_dimensions'] ?? []) as $dimension) {
            $critical[(string) $dimension] = true;
        }

        return [
            'id' => implode('|', [
                (string) ($spec['mode'] ?? 'mode'),
                (string) ($spec['stack'] ?? 'stack'),
                (string) ($spec['risk'] ?? 'R0'),
                (string) ($spec['duration'] ?? 'duration'),
            ]),
            'mode' => (string) ($spec['mode'] ?? ''),
            'stack' => (string) ($spec['stack'] ?? ''),
            'risk' => (string) ($spec['risk'] ?? ''),
            'duration' => (string) ($spec['duration'] ?? ''),
            'unit_ids' => $unitIds,
            'distinct_units' => count($unitIds),
            'power' => (float) ($spec['power'] ?? 0.0),
            'outcome_days' => (int) ($spec['outcome_days'] ?? 0),
            'synthetic' => (bool) ($spec['synthetic'] ?? false),
            'contamination_free' => (bool) ($spec['contamination_free'] ?? false),
            'itt_complete' => (bool) ($spec['itt_complete'] ?? false),
            'critical_dimensions' => $critical,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $specs
     * @param  array<string,mixed>  $overrides  required_exposure, required_outcome_days, required_critical_dimensions
     * @return array<string,mixed> a WorldTrialReadiness-ready manifest
     */
    public function assemble(string $mode, array $specs, array $overrides = []): array
    {
        $campaigns = [];
        $seenUnits = [];
        foreach ($specs as $spec) {
            $campaign = $this->campaign(['mode' => $mode] + $spec);
            foreach ($campaign['unit_ids'] as $unit) {
                if (isset($seenUnits[$unit])) {
                    throw new InvalidArgumentException('campaign_unit_overlap:'.$unit);
                }
                $seenUnits[$unit] = true;
            }
            $campaigns[] = $campaign;
        }

        return [
            'schema_version' => self::SCHEMA,
            'mode' => $mode,
            'campaigns' => $campaigns,
            'required_exposure' => (int) ($overrides['required_exposure'] ?? 1),
            'required_outcome_days' => (int) ($overrides['required_outcome_days'] ?? 30),
            'required_critical_dimensions' => array_values(array_map(
                'strval',
                (array) ($overrides['required_critical_dimensions'] ?? []),
            )),
        ];
    }
}

<?php

namespace App\Services\Ai\Arena;

final class ArenaCapabilityProfileService
{
    public function __construct(private readonly ArenaMeasurementStore $store = new ArenaMeasurementStore) {}

    /** @return array<string, mixed> */
    public function profile(?string $engine = null): array
    {
        $measurements = $this->latestBySuiteArm(array_values(array_filter(
            $this->store->measurements(),
            fn (array $row): bool => $engine === null || $engine === '' || $row['engine'] === $engine
        )));
        $labels = (array) config('atlas_arena.capability_labels_pt', []);

        /** @var array<string, array<string, mixed>> $capabilities */
        $capabilities = [];
        foreach ((array) config('atlas_arena.capability_map', []) as $suite => $entries) {
            if (! is_string($suite) || ! isset($measurements[$suite])) {
                continue;
            }
            $baseline = $measurements[$suite]['baseline'] ?? null;
            $withAtlas = $measurements[$suite]['with_atlas'] ?? null;
            if (! is_array($baseline) && ! is_array($withAtlas)) {
                continue;
            }
            foreach ((array) $entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $capability = (string) ($entry['capability'] ?? '');
                $weight = is_numeric($entry['weight'] ?? null) ? (float) $entry['weight'] : 0.0;
                if ($capability === '' || $weight <= 0.0) {
                    continue;
                }
                $capabilities[$capability] ??= [
                    'capability' => $capability,
                    'label_pt' => (string) ($labels[$capability] ?? $capability),
                    '_baseline_sum' => 0.0,
                    '_baseline_weight' => 0.0,
                    '_atlas_sum' => 0.0,
                    '_atlas_weight' => 0.0,
                    '_suites' => [],
                    '_cases_by_suite' => [],
                ];
                if (is_array($baseline)) {
                    $capabilities[$capability]['_baseline_sum'] += ((float) $baseline['score']) * $weight;
                    $capabilities[$capability]['_baseline_weight'] += $weight;
                }
                if (is_array($withAtlas)) {
                    $capabilities[$capability]['_atlas_sum'] += ((float) $withAtlas['score']) * $weight;
                    $capabilities[$capability]['_atlas_weight'] += $weight;
                }
                $capabilities[$capability]['_suites'][$suite] = true;
                $capabilities[$capability]['_cases_by_suite'][$suite] = max(
                    (int) ($capabilities[$capability]['_cases_by_suite'][$suite] ?? 0),
                    (int) ($baseline['cases_total'] ?? 0),
                    (int) ($withAtlas['cases_total'] ?? 0),
                );
            }
        }

        $public = [];
        foreach ($capabilities as $row) {
            $baselineWeight = (float) $row['_baseline_weight'];
            $atlasWeight = (float) $row['_atlas_weight'];
            $suites = array_keys((array) $row['_suites']);
            sort($suites);
            $public[] = [
                'capability' => $row['capability'],
                'label_pt' => $row['label_pt'],
                'score' => $baselineWeight > 0.0 ? round(((float) $row['_baseline_sum']) / $baselineWeight, 4) : null,
                'with_atlas' => $atlasWeight > 0.0 ? round(((float) $row['_atlas_sum']) / $atlasWeight, 4) : null,
                'suites_contributing' => $suites,
                'cases_total' => array_sum((array) $row['_cases_by_suite']),
            ];
        }
        usort($public, static fn (array $a, array $b): int => strcmp($a['capability'], $b['capability']));

        return [
            'schema_version' => 'atlas.arena.capabilities.v1',
            'mapping_version' => 'arena.capability_map.v1',
            'engine' => $engine,
            'capabilities' => $public,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function latestBySuiteArm(array $rows): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $suite = (string) $row['suite'];
            $arm = (string) $row['arm'];
            if (! isset($latest[$suite][$arm]) || strcmp((string) $row['round_at'], (string) $latest[$suite][$arm]['round_at']) > 0) {
                $latest[$suite][$arm] = $row;
            }
        }

        return $latest;
    }
}

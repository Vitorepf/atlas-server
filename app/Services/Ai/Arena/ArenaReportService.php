<?php

namespace App\Services\Ai\Arena;

/**
 * Projeção provider-safe do relatório empresarial do Rivals para a Arena
 * (atlas.arena.report.v1). Read model: lê enterprise/report.json já
 * construído pelo report-enterprise; NUNCA expõe run ids internos, paths
 * ou detalhes de provider — só números, rótulos públicos e narrativa.
 */
final class ArenaReportService
{
    /** @return array{status_code:int, payload:array<string, mixed>} */
    public function report(): array
    {
        $path = rtrim((string) config('atlas_rivals.storage_root'), '/').'/enterprise/report.json';
        if (! is_file($path)) {
            return [
                'status_code' => 404,
                'payload' => ['error' => 'arena_report_not_published'],
            ];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            return [
                'status_code' => 404,
                'payload' => ['error' => 'arena_report_unreadable'],
            ];
        }

        $summary = (array) ($raw['executive_summary'] ?? []);
        $store = new ArenaMeasurementStore;

        $suites = [];
        foreach ((array) ($raw['suite_rows'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['suite_id'] ?? null)) {
                continue;
            }
            $suites[] = [
                'suite' => $row['suite_id'],
                'status' => (string) ($row['status'] ?? 'unknown'),
                'success_rate' => is_numeric($row['success_rate_itt'] ?? null) ? round((float) $row['success_rate_itt'], 4) : null,
                'intelligence_rate' => is_numeric($row['intelligence_rate'] ?? null) ? round((float) $row['intelligence_rate'], 4) : null,
                'median_wall_ms' => is_numeric($row['median_wall_ms'] ?? null) ? (int) $row['median_wall_ms'] : null,
                'cost_per_task' => is_numeric($row['cost_per_task'] ?? null) ? round((float) $row['cost_per_task'], 4) : null,
                'env_failure_rate' => is_numeric($row['env_failure_rate'] ?? null) ? round((float) $row['env_failure_rate'], 4) : null,
                'pipeline_valid' => ($row['pipeline_valid'] ?? null) === true,
            ];
        }

        $engines = [];
        foreach ((array) ($raw['model_profiles'] ?? []) as $profile) {
            if (! is_array($profile) || ! is_string($profile['model_id'] ?? null)) {
                continue;
            }
            if (! $store->isPublicEngine($profile['model_id'])) {
                continue;
            }
            $edge = static fn (mixed $rows): array => array_values(array_map(
                static fn (array $r): array => [
                    'suite' => (string) ($r['suite_id'] ?? ''),
                    'category' => (string) ($r['category'] ?? ''),
                    'success_rate' => is_numeric($r['success_rate_itt'] ?? null) ? round((float) $r['success_rate_itt'], 4) : null,
                ],
                array_filter((array) $rows, 'is_array'),
            ));
            $engines[] = [
                'engine' => $profile['model_id'],
                'runtimes_measured' => array_values(array_filter((array) ($profile['runtimes_measured'] ?? []), 'is_string')),
                'narrative' => is_string($profile['narrative'] ?? null) ? $profile['narrative'] : null,
                'strengths' => $edge($profile['strengths'] ?? []),
                'weaknesses' => $edge($profile['weaknesses'] ?? []),
            ];
        }

        return [
            'status_code' => 200,
            'payload' => [
                'schema_version' => 'atlas.arena.report.v1',
                'built_at' => is_string($raw['built_at'] ?? null) ? $raw['built_at'] : null,
                'claim_allowed' => ($raw['claim_allowed'] ?? null) === true,
                'claim_blockers' => array_values(array_filter((array) ($raw['claim_blockers'] ?? []), 'is_string')),
                'narrative' => is_string($summary['narrative'] ?? null) ? $summary['narrative'] : null,
                'primary_engine' => is_string($summary['primary_model'] ?? null) ? $summary['primary_model'] : null,
                'suites_ok' => (int) ($summary['suites_ok'] ?? 0),
                'suites_failed' => (int) ($summary['suites_failed'] ?? 0),
                'suites_blocked' => (int) ($summary['suites_blocked'] ?? 0),
                'suites_missing_data' => (int) ($summary['suites_missing_data'] ?? 0),
                'suites' => $suites,
                'engines' => $engines,
            ],
        ];
    }
}

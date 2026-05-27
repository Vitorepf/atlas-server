<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Run Inventory.
 *
 * Read-only operator view over the local runs root. It distinguishes missing
 * historical/external evidence from locally materialized runs and never
 * invokes providers, replay, adjudication, or mutable recovery flows.
 */
final class AtlasForgeRivalsRunInventoryService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.run_inventory.v1';

    private const PREVIEW_LIMIT = 50;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryStateService $battery,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function inventory(array $input): array
    {
        $root = $this->paths->rootDirectory();
        $dirs = $this->runDirectories($root);
        $requested = $this->requestedRunIds($input);

        $rows = [];
        foreach (array_slice(array_reverse($dirs), 0, self::PREVIEW_LIMIT) as $dir) {
            $rows[] = $this->runRow(basename($dir));
        }

        $missing = [];
        foreach ($requested as $runId) {
            $paths = $this->paths->paths($runId);
            if (! is_dir($paths['base'])) {
                $missing[] = $this->missingRunRow($paths);
            }
        }

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'runs_root' => $root,
            'runs_root_exists' => is_dir($root),
            'total_runs' => count($dirs),
            'preview_limit' => self::PREVIEW_LIMIT,
            'runs_preview' => $rows,
            'missing_requested_runs' => $missing,
            'claim_ready' => false,
            'score_or_claim_allowed' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $missing !== []
                ? 'restore missing run evidence or ingest external results before report/replay'
                : 'php artisan atlas:forge:rivals next --run-id=<id> --json',
        ];
    }

    /**
     * @return list<string>
     */
    private function runDirectories(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $dirs = glob($root.'/*', GLOB_ONLYDIR) ?: [];
        $dirs = array_values(array_filter($dirs, static fn (string $path): bool => basename($path) !== ''));
        sort($dirs);

        return $dirs;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function requestedRunIds(array $input): array
    {
        $raw = [];
        if (isset($input['run_id']) && is_string($input['run_id']) && trim($input['run_id']) !== '') {
            $raw[] = trim($input['run_id']);
        }

        $runIds = $input['run_ids'] ?? null;
        if (is_string($runIds)) {
            $raw = array_merge($raw, explode(',', $runIds));
        } elseif (is_array($runIds)) {
            foreach ($runIds as $entry) {
                if (is_string($entry)) {
                    $raw = array_merge($raw, explode(',', $entry));
                }
            }
        }

        $out = [];
        foreach ($raw as $entry) {
            $id = trim((string) $entry);
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string,mixed>
     */
    private function runRow(string $runId): array
    {
        $paths = $this->paths->paths($runId);
        $battery = $this->battery->snapshot($paths['run_id']);
        $manifestPresent = is_file($paths['manifest_json']);
        $evidencePackPresent = is_file($paths['evidence'].'/evidence_pack.json');
        $scorecardPresent = is_file($paths['scorecard_json']) || is_file($paths['scorecard_v2_json']);
        $reportPresent = is_file($paths['report_md'])
            || is_file($paths['evidence'].'/battery_report.md')
            || is_file($paths['evidence'].'/matrix_report.md');
        $batteryPresent = ($battery['exists'] ?? false) === true;
        $eventsPresent = is_file($paths['events_jsonl']);

        return [
            'run_id' => $paths['run_id'],
            'run_dir' => $paths['base'],
            'phase' => $this->phase(
                $batteryPresent,
                $eventsPresent,
                $manifestPresent,
                $evidencePackPresent,
                $scorecardPresent,
                $reportPresent,
            ),
            'manifest_present' => $manifestPresent,
            'events_present' => $eventsPresent,
            'battery_present' => $batteryPresent,
            'evidence_pack_present' => $evidencePackPresent,
            'scorecard_present' => $scorecardPresent,
            'report_present' => $reportPresent,
            'replay_candidate' => $evidencePackPresent,
            'claim_candidate' => false,
            'score_or_claim_allowed' => false,
            'progress' => $this->progress($battery),
            'paths' => [
                'manifest_json' => $paths['manifest_json'],
                'evidence_pack_json' => $paths['evidence'].'/evidence_pack.json',
                'scorecard_json' => $paths['scorecard_json'],
                'scorecard_v2_json' => $paths['scorecard_v2_json'],
                'report_md' => $paths['report_md'],
            ],
            'next_command' => $evidencePackPresent
                ? 'php artisan atlas:forge:rivals replay --run-id='.$paths['run_id'].' --json --strict'
                : 'php artisan atlas:forge:rivals next --run-id='.$paths['run_id'].' --json',
        ];
    }

    /**
     * @param  array<string,string>  $paths
     * @return array<string,mixed>
     */
    private function missingRunRow(array $paths): array
    {
        $externalLike = $this->looksLikeExternalEvidenceRun($paths['run_id']);

        return [
            'run_id' => $paths['run_id'],
            'expected_run_dir' => $paths['base'],
            'phase' => $externalLike ? 'external_evidence_missing' : 'run_dir_missing',
            'blockers' => $externalLike
                ? ['run_not_found:'.$paths['run_id'], 'external_evidence_artifact_missing']
                : ['run_not_found:'.$paths['run_id']],
            'restore_required' => true,
            'score_or_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'next_command' => 'php artisan atlas:forge:rivals next --run-id='.$paths['run_id'].' --json',
        ];
    }

    private function phase(
        bool $batteryPresent,
        bool $eventsPresent,
        bool $manifestPresent,
        bool $evidencePackPresent,
        bool $scorecardPresent,
        bool $reportPresent,
    ): string {
        if ($reportPresent && $scorecardPresent && $evidencePackPresent) {
            return 'report_materialized';
        }
        if ($scorecardPresent && $evidencePackPresent) {
            return 'scorecard_materialized';
        }
        if ($evidencePackPresent) {
            return 'evidence_pack_materialized';
        }
        if ($batteryPresent) {
            return 'battery_materialized';
        }
        if ($eventsPresent) {
            return 'events_materialized';
        }
        if ($manifestPresent) {
            return 'manifest_only';
        }

        return 'run_dir_only';
    }

    /**
     * @param  array<string,mixed>  $battery
     * @return array<string,int>
     */
    private function progress(array $battery): array
    {
        $counts = is_array($battery['state_counts'] ?? null) ? $battery['state_counts'] : [];

        return [
            'total' => (int) ($battery['case_count'] ?? 0),
            'passed' => (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED] ?? 0),
            'failed' => (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED] ?? 0),
            'invalid' => (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID] ?? 0),
            'running' => (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING] ?? 0),
            'pending' => (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING] ?? 0),
            'skipped' => (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED] ?? 0),
            'remaining' => (int) ($battery['pending_case_count'] ?? 0) + (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING] ?? 0),
        ];
    }

    private function looksLikeExternalEvidenceRun(string $runId): bool
    {
        return str_starts_with($runId, 'battery-')
            || str_starts_with($runId, 'deepswe-')
            || str_starts_with($runId, 'arena-')
            || str_contains($runId, 'external');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Battery Evidence (multi-case aggregate v1).
 *
 * Builds a battery-level evidence pack from N already-existing per-run
 * evidence packs. The intent is to give the operator a single, replayable
 * manifest that captures everything needed to audit a multi-case battery:
 *
 *   - The list of run_ids that composed the battery and their per-case
 *     `evidence_pack.json` hash.
 *   - A category summary (counts by `task_category`).
 *   - A difficulty summary on the canonical L1-L5 ladder (counts and
 *     weighted aggregate). Missing difficulty in any case blocks the claim.
 *   - A per-case digest with patch diff / test log / receipt / workspace
 *     hashes / verdict.
 *   - `aggregate_claim_ready=false` — the battery service never promotes a
 *     claim. The Adjudicator + report still own that decision.
 *
 * The service never invokes a provider, never mutates state outside writing
 * `runs/<run_id>/evidence/battery_evidence_pack.json` (or the writeable
 * `--output-path` override). It is read-only over the per-run evidence
 * directories.
 *
 * Schema: `atlas.forge.rivals.battery_evidence_pack.v1`
 */
final class AtlasForgeRivalsBatteryEvidenceService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.battery_evidence_pack.v1';

    /** File written alongside the per-run packs that anchors the battery. */
    public const BATTERY_PACK_FILE = 'battery_evidence_pack.json';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsCollectEvidenceService $collect,
    ) {}

    /**
     * Resolve the run_ids the operator requested. Accepts either a
     * `--run-ids=a,b,c` CSV or a repeated `--run-id` list. Trims, dedupes,
     * preserves order.
     *
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    public static function resolveRunIds(array $input): array
    {
        $ids = [];
        $raw = $input['run_ids'] ?? null;
        if (is_string($raw)) {
            foreach (explode(',', $raw) as $piece) {
                $id = trim($piece);
                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $piece) {
                $id = trim((string) $piece);
                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        $single = trim((string) ($input['run_id'] ?? ''));
        if ($single !== '' && ! in_array($single, $ids, true)) {
            $ids[] = $single;
        }

        return $ids;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function aggregate(array $input): array
    {
        $runIds = self::resolveRunIds($input);
        if ($runIds === []) {
            return $this->blocked(['run_ids_required'], 'pass --run-id=<id> (repeat or comma-separated)');
        }

        $stage = AtlasForgeRivalsEvidencePolicy::normalizeStage((string) ($input['evidence_stage'] ?? ''));
        $batteryId = $this->resolveBatteryId($input, $runIds);

        $batteryRuns = [];
        $cases = [];
        $categoryCounts = [];
        $difficultyCounts = array_fill_keys(AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, 0);
        $missingDifficulty = [];
        $missingArtifacts = [];
        $invalidReasons = [];
        $totalWeight = 0.0;
        $modesSeen = [];
        $providerCall = false;
        $tokensSpent = false;

        foreach ($runIds as $runId) {
            $paths = $this->paths->paths($runId);
            if (! is_dir($paths['base'])) {
                $missingArtifacts[] = 'run_not_found:'.$paths['run_id'];
                $batteryRuns[] = [
                    'run_id' => $paths['run_id'],
                    'present' => false,
                    'reason_missing' => 'run_directory_not_found',
                ];

                continue;
            }

            // Ensure the per-run pack is current, then load it.
            $packResult = $this->collect->collect([
                'run_id' => $paths['run_id'],
                'evidence_stage' => $stage,
            ]);
            $pack = is_array($packResult['evidence_pack'] ?? null) ? $packResult['evidence_pack'] : [];
            $packPath = $paths['evidence'].'/evidence_pack.json';
            $packHash = is_file($packPath) ? (hash_file('sha256', $packPath) ?: null) : null;
            $manifest = is_file($paths['manifest_json']) ? $this->readJson($paths['manifest_json']) : [];
            $modeForEvidence = (string) ($pack['mode_for_evidence'] ?? '');
            if ($modeForEvidence !== '') {
                $modesSeen[$modeForEvidence] = ($modesSeen[$modeForEvidence] ?? 0) + 1;
            }
            $providerCall = $providerCall || (bool) ($pack['external_provider_call'] ?? false);
            $tokensSpent = $tokensSpent || (bool) ($pack['provider_tokens_may_have_been_spent'] ?? false);

            $runEntry = [
                'run_id' => $paths['run_id'],
                'present' => true,
                'pack_path' => $packPath,
                'pack_sha256' => $packHash,
                'evidence_stage' => $pack['evidence_stage'] ?? null,
                'mode_for_evidence' => $modeForEvidence,
                'is_multi_case' => (bool) ($pack['is_multi_case'] ?? false),
                'case_count' => (int) ($pack['case_count'] ?? 0),
                'verdict' => $pack['verdict'] ?? null,
                'claim_ready' => (bool) ($pack['claim_ready'] ?? false),
                'missing_required' => $this->stringList($pack['missing_required'] ?? []),
                'after_clean_check_clean' => $pack['after_clean_check']['clean'] ?? null,
                'after_clean_check_ran' => (bool) ($pack['after_clean_check']['ran'] ?? false),
                'tracked_bytecode_artifacts' => $this->stringList($pack['tracked_bytecode_artifacts'] ?? []),
            ];
            $batteryRuns[] = $runEntry;

            $packCases = is_array($pack['cases'] ?? null) ? $pack['cases'] : [];
            if ($packCases === []) {
                // Single-case run: synthesise a case digest from the pack's
                // run-level fields so the battery aggregate is uniform.
                $packCases = [$this->synthesizeSingleCaseDigest($pack, $manifest, $paths)];
            }
            foreach ($packCases as $case) {
                if (! is_array($case)) {
                    continue;
                }
                $caseId = (string) ($case['case_id'] ?? '');
                if ($caseId === '') {
                    continue;
                }
                $taskCategory = isset($case['task_category']) && is_string($case['task_category'])
                    ? $case['task_category']
                    : '';
                if ($taskCategory !== '') {
                    $categoryCounts[$taskCategory] = ($categoryCounts[$taskCategory] ?? 0) + 1;
                }
                $level = isset($case['difficulty_level']) ? (string) $case['difficulty_level'] : '';
                if ($level === '' || ! in_array($level, AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, true)) {
                    $missingDifficulty[] = $paths['run_id'].'/'.$caseId;
                } else {
                    $difficultyCounts[$level]++;
                    $totalWeight += AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level);
                }
                $cases[] = $this->buildBatteryCaseEntry($paths['run_id'], $case);
            }
        }

        if ($missingDifficulty !== []) {
            $invalidReasons[] = 'missing_difficulty_level_for_cases:'.implode(',', array_slice($missingDifficulty, 0, 8));
        }

        $replayStatus = $this->summarizeReplayStatus($cases);
        $blockers = array_values(array_unique(array_merge($missingArtifacts, $invalidReasons, $replayStatus['blockers'])));
        $status = $blockers === [] ? 'passed' : 'invalid_missing_evidence';

        $pack = [
            'schema_version' => self::SCHEMA_VERSION,
            'battery_id' => $batteryId,
            'evidence_stage' => $stage,
            'generated_at' => now()->toJSON(),
            'run_ids' => array_values(array_map(static fn (array $r): string => (string) $r['run_id'], $batteryRuns)),
            'runs' => $batteryRuns,
            'cases' => $cases,
            'case_count' => count($cases),
            'category_summary' => [
                'counts' => $categoryCounts,
                'present_categories' => array_keys($categoryCounts),
            ],
            'difficulty_summary' => [
                'ladder' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS,
                'counts' => $difficultyCounts,
                'missing_difficulty_count' => count($missingDifficulty),
                'missing_difficulty_cases' => $missingDifficulty,
                'weights' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_SCORE_WEIGHTS,
                'total_weight' => $totalWeight,
            ],
            'replay_status' => $replayStatus,
            'mode_distribution' => $modesSeen,
            'blockers' => $blockers,
            'invalid_reasons' => $invalidReasons,
            'aggregate_claim_ready' => false,
            'external_provider_call' => $providerCall,
            'provider_tokens_may_have_been_spent' => $tokensSpent,
            'external_rivals_certification_status' => 'blocked',
            'separated_from_external_rivals_certification' => true,
        ];

        $outputPath = $this->resolveOutputPath($input, $batteryRuns);
        $priorPack = null;
        if ($outputPath !== null && is_file($outputPath)) {
            $priorBlob = (string) @file_get_contents($outputPath);
            $decoded = json_decode($priorBlob, true);
            if (is_array($decoded)) {
                $priorPack = $decoded;
            }
        }
        if ($outputPath !== null) {
            @mkdir(dirname($outputPath), 0o755, true);
            file_put_contents($outputPath, $this->jsonEncode($pack));
            $pack['battery_pack_path'] = $outputPath;
            $pack['battery_pack_sha256'] = hash_file('sha256', $outputPath) ?: null;
        }

        return [
            'status' => $status === 'passed' ? 'ok' : $status,
            'battery_id' => $batteryId,
            'battery_evidence_pack' => $pack,
            'prior_battery_evidence_pack' => $priorPack,
            'blockers' => $blockers,
            'next_command' => $blockers === []
                ? 'php artisan atlas:forge:rivals battery-verify-evidence --battery-id='.$batteryId.' --json --strict'
                : 'fix the listed blockers before aggregating again',
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * Build a per-case entry the battery exposes downstream. Pulls the
     * arm-level patch diff / test log paths and sha256 from the case digest
     * the collector already produced; also rehashes the on-disk artifact so
     * a tampered patch is caught at battery aggregation time.
     *
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function buildBatteryCaseEntry(string $runId, array $case): array
    {
        $arms = is_array($case['arms'] ?? null) ? $case['arms'] : [];
        $atlas = is_array($arms['atlas'] ?? null) ? $arms['atlas'] : [];
        $rival = is_array($arms['rival'] ?? null) ? $arms['rival'] : [];

        return [
            'run_id' => $runId,
            'case_id' => (string) ($case['case_id'] ?? ''),
            'case_index' => (int) ($case['case_index'] ?? 0),
            'task_category' => $case['task_category'] ?? null,
            'difficulty' => $case['difficulty'] ?? null,
            'difficulty_level' => $case['difficulty_level'] ?? null,
            'difficulty_level_origin' => $case['difficulty_level_origin'] ?? 'missing',
            'difficulty_weight' => $case['difficulty_weight'] ?? null,
            'verdict' => $case['verdict'] ?? null,
            'evidence_subdir' => $case['evidence_subdir'] ?? null,
            'evidence_path' => $case['evidence_path'] ?? null,
            'workspace_hash_before' => $case['workspace_hash_before'] ?? null,
            'workspace_hash_after' => $case['workspace_hash_after'] ?? null,
            'workspace_blockers' => $case['workspace_blockers'] ?? [],
            'replay_ready' => $this->caseLooksReplayable($atlas, $rival, $case),
            'arms' => [
                'atlas' => [
                    'present' => (bool) ($atlas['present'] ?? false),
                    'reason_missing' => $atlas['reason_missing'] ?? null,
                    'patch_diff_path' => $atlas['patch_diff_path'] ?? null,
                    'patch_diff_sha256' => $atlas['patch_diff_on_disk_sha256'] ?? ($atlas['patch_diff_hash'] ?? null),
                    'patch_diff_bytes' => $atlas['patch_diff_bytes'] ?? 0,
                    'test_log_path' => $atlas['test_log_path'] ?? null,
                    'test_log_sha256' => $atlas['test_log_on_disk_sha256'] ?? null,
                    'exit_code' => $atlas['exit_code'] ?? null,
                    'test_exit_code' => $atlas['test_exit_code'] ?? null,
                    'killed' => (bool) ($atlas['killed'] ?? false),
                ],
                'rival' => [
                    'present' => (bool) ($rival['present'] ?? false),
                    'reason_missing' => $rival['reason_missing'] ?? null,
                    'patch_diff_path' => $rival['patch_diff_path'] ?? null,
                    'patch_diff_sha256' => $rival['patch_diff_on_disk_sha256'] ?? ($rival['patch_diff_hash'] ?? null),
                    'patch_diff_bytes' => $rival['patch_diff_bytes'] ?? 0,
                    'test_log_path' => $rival['test_log_path'] ?? null,
                    'test_log_sha256' => $rival['test_log_on_disk_sha256'] ?? null,
                    'exit_code' => $rival['exit_code'] ?? null,
                    'test_exit_code' => $rival['test_exit_code'] ?? null,
                    'killed' => (bool) ($rival['killed'] ?? false),
                ],
            ],
        ];
    }

    /**
     * Synthesise a case digest for a single-case run that did not produce
     * `cases[]` in its evidence pack. Walks the pack's provider_receipts and
     * workspace fields so the battery surface stays uniform.
     *
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $manifest
     * @param  array<string,string>  $paths
     * @return array<string,mixed>
     */
    private function synthesizeSingleCaseDigest(array $pack, array $manifest, array $paths): array
    {
        $caseId = (string) ($manifest['case_id'] ?? $pack['case_id'] ?? '');
        $taskCategory = $manifest['task_category'] ?? null;
        $providerReceipts = is_array($pack['provider_receipts'] ?? null) ? $pack['provider_receipts'] : [];
        $atlasReceipt = is_array($providerReceipts['atlas'] ?? null) ? $providerReceipts['atlas'] : [];
        $rivalReceipt = is_array($providerReceipts['rival'] ?? null) ? $providerReceipts['rival'] : [];

        $artifacts = is_array($pack['artifacts'] ?? null) ? $pack['artifacts'] : [];
        $atlasPatch = is_array($artifacts['atlas_patch'] ?? null) ? $artifacts['atlas_patch'] : [];
        $rivalPatch = is_array($artifacts['rival_patch'] ?? null) ? $artifacts['rival_patch'] : [];
        $atlasTestLog = is_array($artifacts['atlas_test_log'] ?? null) ? $artifacts['atlas_test_log'] : [];
        $rivalTestLog = is_array($artifacts['rival_test_log'] ?? null) ? $artifacts['rival_test_log'] : [];

        return [
            'case_id' => $caseId,
            'case_index' => 0,
            'task_category' => $taskCategory,
            'case_source' => 'single_case_legacy',
            'case_set' => null,
            'difficulty' => null,
            'difficulty_level' => null,
            'difficulty_level_origin' => 'missing',
            'difficulty_weight' => null,
            'verdict' => $pack['verdict'] ?? $manifest['verdict'] ?? 'unknown',
            'evidence_subdir' => null,
            'evidence_path' => $paths['evidence'],
            'workspace_hash_before' => $pack['workspace_hash_before'] ?? null,
            'workspace_hash_after' => $pack['workspace_hash_after'] ?? null,
            'workspace_blockers' => $this->stringList($manifest['workspace_blockers'] ?? []),
            'arms' => [
                'atlas' => [
                    'present' => (bool) ($atlasReceipt['present'] ?? false),
                    'reason_missing' => $atlasReceipt['reason_missing'] ?? null,
                    'patch_diff_path' => $atlasPatch['path'] ?? null,
                    'patch_diff_hash' => $atlasReceipt['patch_diff_hash'] ?? ($atlasPatch['sha256'] ?? null),
                    'patch_diff_on_disk_sha256' => $atlasPatch['sha256'] ?? null,
                    'patch_diff_bytes' => (int) ($atlasReceipt['patch_diff_bytes'] ?? ($atlasPatch['bytes'] ?? 0)),
                    'test_log_path' => $atlasTestLog['path'] ?? null,
                    'test_log_on_disk_sha256' => $atlasTestLog['sha256'] ?? null,
                    'exit_code' => $atlasReceipt['exit_code'] ?? null,
                    'test_exit_code' => $atlasReceipt['test_exit_code'] ?? null,
                    'killed' => (bool) ($atlasReceipt['killed'] ?? false),
                ],
                'rival' => [
                    'present' => (bool) ($rivalReceipt['present'] ?? false),
                    'reason_missing' => $rivalReceipt['reason_missing'] ?? null,
                    'patch_diff_path' => $rivalPatch['path'] ?? null,
                    'patch_diff_hash' => $rivalReceipt['patch_diff_hash'] ?? ($rivalPatch['sha256'] ?? null),
                    'patch_diff_on_disk_sha256' => $rivalPatch['sha256'] ?? null,
                    'patch_diff_bytes' => (int) ($rivalReceipt['patch_diff_bytes'] ?? ($rivalPatch['bytes'] ?? 0)),
                    'test_log_path' => $rivalTestLog['path'] ?? null,
                    'test_log_on_disk_sha256' => $rivalTestLog['sha256'] ?? null,
                    'exit_code' => $rivalReceipt['exit_code'] ?? null,
                    'test_exit_code' => $rivalReceipt['test_exit_code'] ?? null,
                    'killed' => (bool) ($rivalReceipt['killed'] ?? false),
                ],
            ],
        ];
    }

    /**
     * A case is replay-ready when both arms have a patch_diff sha256 and a
     * test log sha256 on disk, and the verdict is `comparable`. Anything else
     * surfaces as `replay_ready=false` and feeds the replay summary blockers.
     *
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     * @param  array<string,mixed>  $case
     */
    private function caseLooksReplayable(array $atlas, array $rival, array $case): bool
    {
        $verdict = (string) ($case['verdict'] ?? '');
        if ($verdict !== 'comparable') {
            return false;
        }
        foreach ([$atlas, $rival] as $arm) {
            if (! ($arm['present'] ?? false)) {
                return false;
            }
            if (empty($arm['patch_diff_on_disk_sha256'] ?? $arm['patch_diff_hash'] ?? null)) {
                return false;
            }
            if (empty($arm['test_log_on_disk_sha256'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aggregate replay readiness across every case. Counts the comparable
     * cases that pass and the cases that block; surfaces the blocker set so
     * the verifier can fail closed in one step.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array{
     *   total_cases: int,
     *   replayable_count: int,
     *   blocked_count: int,
     *   blockers: list<string>,
     *   status: string,
     * }
     */
    private function summarizeReplayStatus(array $cases): array
    {
        $total = count($cases);
        $replayable = 0;
        $blockers = [];
        foreach ($cases as $case) {
            if ($case['replay_ready'] ?? false) {
                $replayable++;
            } else {
                $blockers[] = 'case_not_replayable:'.($case['run_id'] ?? '').'/'.($case['case_id'] ?? '');
            }
        }
        $blocked = $total - $replayable;
        $status = $blocked === 0 && $total > 0 ? 'all_cases_replayable' : 'blocked';

        return [
            'total_cases' => $total,
            'replayable_count' => $replayable,
            'blocked_count' => $blocked,
            'blockers' => $blockers,
            'status' => $status,
        ];
    }

    /**
     * Resolve the canonical battery_id. Honors `--battery-id=<id>`; otherwise
     * derives `battery-<sha8>` from the sorted run_ids so the same run set
     * always reproduces the same id.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $runIds
     */
    private function resolveBatteryId(array $input, array $runIds): string
    {
        $explicit = trim((string) ($input['battery_id'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $sorted = $runIds;
        sort($sorted, SORT_STRING);
        $hash = substr(hash('sha256', implode('|', $sorted)), 0, 8);

        return 'battery-'.$hash;
    }

    /**
     * Output path policy:
     *   - `--output-path` wins when set,
     *   - otherwise write inside the first run's `evidence/` directory so the
     *     battery pack lives next to its constituent per-run packs.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $batteryRuns
     */
    private function resolveOutputPath(array $input, array $batteryRuns): ?string
    {
        $explicit = trim((string) ($input['output_path'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        foreach ($batteryRuns as $run) {
            if (($run['present'] ?? false) !== true) {
                continue;
            }
            $packPath = (string) ($run['pack_path'] ?? '');
            if ($packPath === '') {
                continue;
            }

            return dirname($packPath).'/'.self::BATTERY_PACK_FILE;
        }

        return null;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, string $hint): array
    {
        return [
            'status' => 'blocked',
            'blockers' => $blockers,
            'external_provider_call' => false,
            'next_command' => $hint,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $blob = (string) @file_get_contents($path);
        $row = json_decode($blob, true);

        return is_array($row) ? $row : [];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

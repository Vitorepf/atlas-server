<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Atlas Forge Rivals · Collect Evidence (v2 hardened).
 *
 * Assembles the canonical evidence pack for a run by enumerating files in
 * `runs/<run_id>/evidence/`, the run's `events.jsonl` + `intent.json`, and
 * hashing every artifact for the replay step.
 *
 * Two stages exist (see {@see AtlasForgeRivalsEvidencePolicy}):
 *   - `pre_adjudication`: collected BEFORE the adjudicator writes the
 *     scorecard. `scorecard` is intentionally not enumerated.
 *   - `final`: collected AFTER adjudication. `scorecard` is enumerated and
 *     required; replay (final stage) re-hashes it.
 *
 * The `missing_evidence` v1 field is preserved (it equals the v2
 * `missing_required` list, prefixed with `missing_evidence:` for legacy
 * adjudicator hard-gate `evidence_complete`).
 *
 * Evidence Pack + Replay Hardening v2 (2026-05-15) additions, all additive:
 *   - `provider_receipts` summary (per arm: exit_code, killed, test_mode flag,
 *     command/prompt/stdout/stderr/test_log hashes, source).
 *   - `workspace_hash_before` / `workspace_hash_after` / `after_clean_check`
 *     surfaced from the run manifest so the verifier never has to re-derive
 *     them from disk.
 *   - `tracked_bytecode_artifacts` aggregated across arms (any tracked .pyc
 *     blocks claim).
 *   - `reason_missing` populated for every artifact where `present=false` so
 *     "missing without explanation" can never sneak through.
 *   - `claim_ready=false` is enforced when any required artifact is missing.
 *   - `external_rivals_certification_status` is always `blocked`.
 *   - Top-level `mode_for_evidence` records the operator mode the evidence
 *     was produced under (fair|full_power|local_fake). Verifier uses this to
 *     decide which strict checks to apply.
 *   - Sidecar `artifact_index.json` lists every artifact key, path, sha256,
 *     bytes, policy, reason_missing for replay-without-pack and CI audits.
 *
 * Never invokes provider. Read-only.
 *
 * Schema: `atlas.forge.rivals.evidence_pack.v2` (v1 fields preserved).
 * Sidecar schema: `atlas.forge.rivals.artifact_index.v1`.
 */
final class AtlasForgeRivalsCollectEvidenceService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.evidence_pack.v2';

    public const ARTIFACT_INDEX_SCHEMA_VERSION = 'atlas.forge.rivals.artifact_index.v1';

    public const SCHEMA_VERSION_LEGACY = 'atlas.forge.rivals.evidence_pack.v1';

    /** Canonical evidence-mode values surfaced by the pack. */
    public const EVIDENCE_MODE_DRY_RUN = 'dry_run';

    public const EVIDENCE_MODE_FAKE_RUN = 'fake_run';

    public const EVIDENCE_MODE_REAL_RUN = 'real_run';

    public const EVIDENCE_MODE_UNKNOWN = 'unknown';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function collect(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id=<id> --json',
            ];
        }
        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$paths['run_id']],
                'next_command' => '',
            ];
        }

        $manifest = $this->readJson($paths['manifest_json']);
        $stage = AtlasForgeRivalsEvidencePolicy::normalizeStage((string) ($input['evidence_stage'] ?? ''));
        $plan = AtlasForgeRivalsEvidencePolicy::plan($stage, $manifest);

        $artifactPaths = [
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
            'atlas_patch' => $paths['evidence'].'/atlas_patch.diff',
            'rival_patch' => $paths['evidence'].'/rival_patch.diff',
            'atlas_test_log' => $paths['evidence'].'/atlas_test.log',
            'rival_test_log' => $paths['evidence'].'/rival_test.log',
            'scorecard' => $paths['scorecard_json'],
        ];
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage($plan['stage']);

        $artifacts = [];
        foreach ($keys as $key) {
            $path = $artifactPaths[$key] ?? null;
            if ($path === null) {
                continue;
            }
            $policy = $plan['policy'][$key] ?? AtlasForgeRivalsEvidencePolicy::POLICY_OPTIONAL;
            $desc = $this->describeFile($path);
            $desc['policy'] = $policy;
            if (! ($desc['present'] ?? false)) {
                $desc['reason_missing'] = $this->reasonMissing($key, $policy, $plan);
            }
            $artifacts[$key] = $desc;
        }

        $missingRequired = [];
        foreach ($plan['required'] as $required) {
            if (! ($artifacts[$required]['present'] ?? false)) {
                $missingRequired[] = $required;
            }
        }
        $missingOptional = [];
        foreach ($plan['optional'] as $optional) {
            if (! ($artifacts[$optional]['present'] ?? false)) {
                $missingOptional[] = $optional;
            }
        }

        // Legacy v1 field: `missing_evidence` is the list of `missing_evidence:<key>`
        // strings (the format the adjudicator's `evidence_complete` hard gate
        // currently consumes).
        $missingEvidence = array_map(
            static fn (string $k): string => 'missing_evidence:'.$k,
            $missingRequired,
        );

        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $claimReady = (bool) ($manifest['claim_ready'] ?? false);
        if (str_starts_with($verdict, 'invalid') || $missingRequired !== []) {
            $claimReady = false;
        }
        if ($plan['stage'] === AtlasForgeRivalsEvidencePolicy::STAGE_FINAL && ($artifacts['scorecard']['present'] ?? false)) {
            $scorecard = $this->readJson($paths['scorecard_json']);
            $claimReady = $claimReady && (bool) ($scorecard['claim_ready'] ?? false);
        }

        $atlasReceipt = $this->readJson($paths['evidence'].'/atlas_receipt.json');
        $rivalReceipt = $this->readJson($paths['evidence'].'/rival_receipt.json');
        $providerReceipts = $this->summarizeProviderReceipts($atlasReceipt, $rivalReceipt, $manifest);
        $workspaceHashes = $this->readJson($paths['evidence'].'/workspace_hashes.json');
        $afterCleanCheck = $this->summarizeAfterCleanCheck($manifest, $workspaceHashes, $atlasReceipt, $rivalReceipt);
        $trackedBytecode = $this->aggregateBytecodeArtifacts($atlasReceipt, $rivalReceipt);
        $modeForEvidence = $this->resolveEvidenceMode($manifest);
        $externalProviderCall = (bool) ($manifest['external_provider_call'] ?? false);
        $providerTokensMayHaveBeenSpent = (bool) ($manifest['provider_tokens_spent'] ?? $externalProviderCall);
        $multiCase = $this->buildMultiCaseSummary($manifest, $paths);

        $pack = [
            'schema_version' => self::SCHEMA_VERSION,
            'evidence_stage' => $plan['stage'],
            'mode_for_evidence' => $modeForEvidence,
            'run_id' => $paths['run_id'],
            'collected_at' => now()->toJSON(),
            'paths' => $paths,
            'artifacts' => $artifacts,
            'required_artifacts' => $plan['required'],
            'optional_artifacts' => $plan['optional'],
            'artifact_policy' => $plan['policy'],
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'missing_evidence' => $missingEvidence,
            'verdict' => $verdict,
            'claim_ready' => $claimReady,
            'score' => $manifest['score'] ?? null,
            'manifest_summary' => $manifest === [] ? null : [
                'mode' => $manifest['mode'] ?? null,
                'preset' => $manifest['preset'] ?? null,
                'atlas_model' => $manifest['atlas_model'] ?? null,
                'rival_model' => $manifest['rival_model'] ?? null,
                'case_id' => $manifest['case_id'] ?? null,
                'dirty_after_run' => $manifest['dirty_after_run'] ?? null,
            ],
            'arena_contracts' => is_array($manifest['arena_contracts'] ?? null) ? $manifest['arena_contracts'] : null,
            'meta_provider_evidence_contract' => $this->metaProviderEvidenceContract($manifest),
            'is_comparable_real_run' => $plan['is_comparable_real_run'],
            'workspace_hash_before' => $manifest['workspace_hash_before'] ?? ($workspaceHashes['before'] ?? null),
            'workspace_hash_after' => $manifest['workspace_hash_after'] ?? ($workspaceHashes['after'] ?? null),
            'after_clean_check' => $afterCleanCheck,
            'provider_receipts' => $providerReceipts,
            'tracked_bytecode_artifacts' => $trackedBytecode,
            'external_provider_call' => $externalProviderCall,
            'provider_tokens_spent' => $providerTokensMayHaveBeenSpent,
            'provider_tokens_may_have_been_spent' => $providerTokensMayHaveBeenSpent,
            'external_rivals_certification_status' => 'blocked',
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'is_multi_case' => $multiCase['is_multi_case'],
            'case_count' => $multiCase['case_count'],
            'cases' => $multiCase['cases'],
            'category_summary' => $multiCase['category_summary'],
            'difficulty_summary' => $multiCase['difficulty_summary'],
        ];

        @mkdir($paths['evidence'], 0o755, true);
        $packJson = (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($paths['evidence'].'/evidence_pack.json', $packJson);

        $artifactIndex = $this->buildArtifactIndex($paths['run_id'], $plan['stage'], $artifacts, $plan);
        file_put_contents(
            $paths['evidence'].'/artifact_index.json',
            (string) json_encode($artifactIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return [
            'status' => $missingRequired === [] ? 'ok' : 'blocked',
            'run_id' => $paths['run_id'],
            'evidence_stage' => $plan['stage'],
            'evidence_pack' => $pack,
            'blockers' => $missingEvidence,
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'evidence_paths' => array_values(array_filter(array_map(
                static fn (array $a): ?string => ($a['present'] ?? false) ? (string) $a['path'] : null,
                $artifacts,
            ))),
            'next_command' => $missingRequired === []
                ? 'php artisan atlas:forge:rivals replay --run-id='.$paths['run_id'].' --stage='.$plan['stage'].' --json'
                : 'check missing artifacts and rerun the run',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeFile(string $path): array
    {
        if (! is_file($path)) {
            return ['path' => $path, 'present' => false];
        }
        $bytes = (int) @filesize($path);

        return [
            'path' => $path,
            'present' => true,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $path) ?: null,
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
        $this->ensureJsonReadMemoryBudget((int) (@filesize($path) ?: 0));
        $blob = (string) @file_get_contents($path);
        $row = json_decode($blob, true);

        return is_array($row) ? $row : [];
    }

    private function ensureJsonReadMemoryBudget(int $bytes): void
    {
        if ($bytes < 8 * 1024 * 1024) {
            return;
        }

        $current = $this->memoryLimitBytes((string) ini_get('memory_limit'));
        $target = 1024 * 1024 * 1024;
        if ($current > 0 && $current < $target) {
            @ini_set('memory_limit', (string) $target);
        }
    }

    private function memoryLimitBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    /**
     * Reason why a given artifact key is absent. Required artifacts get a
     * blocking-flavored reason; optional artifacts get the policy-explained
     * reason so the verifier never has to guess.
     *
     * @param  array<string,mixed>  $plan
     */
    private function reasonMissing(string $key, string $policy, array $plan): string
    {
        if ($policy === AtlasForgeRivalsEvidencePolicy::POLICY_REQUIRED) {
            return 'required_artifact_absent_on_disk:'.$key;
        }
        $verdict = (string) ($plan['verdict'] ?? '');
        $mode = (string) ($plan['mode'] ?? '');
        if ($mode === 'local_fake') {
            return 'optional_for_local_fake_mode:'.$key;
        }
        if (str_starts_with($verdict, 'invalid')) {
            return 'optional_for_invalid_verdict:'.$verdict;
        }
        if ($verdict === 'inconclusive' || $verdict === 'unknown') {
            return 'optional_for_'.$verdict.'_verdict';
        }
        if ($key === 'scorecard') {
            return 'scorecard_not_required_for_pre_adjudication_stage';
        }

        return 'optional_artifact_absent:'.$key;
    }

    /**
     * Summary of per-arm provider receipts for the verifier and the artifact
     * index. Each entry carries the hashes + the booleans needed to decide if
     * a real_run was satisfied or if a fake_run is being recycled. The
     * `test_mode` flag is set to true when a receipt was produced by the
     * local_fake in-process adapter — real_run must reject those.
     *
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function summarizeProviderReceipts(array $atlasReceipt, array $rivalReceipt, array $manifest): array
    {
        $mode = strtolower((string) ($manifest['mode'] ?? ''));

        return [
            'atlas' => $this->summarizeArmReceipt($atlasReceipt, $mode),
            'rival' => $this->summarizeArmReceipt($rivalReceipt, $mode),
            'count_present' => ($atlasReceipt === [] ? 0 : 1) + ($rivalReceipt === [] ? 0 : 1),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function summarizeArmReceipt(array $receipt, string $mode): array
    {
        if ($receipt === []) {
            return [
                'present' => false,
                'reason_missing' => 'provider_receipt_not_supplied',
                'test_mode' => false,
                'fake' => false,
                'killed' => false,
                'exit_code' => null,
                'test_exit_code' => null,
                'patch_diff_bytes' => 0,
                'stdout_hash' => null,
                'stderr_hash' => null,
                'command_hash' => null,
                'prompt_hash' => null,
                'prompt_transport' => null,
                'stdin_prompt_hash' => null,
                'stdin_prompt_bytes' => null,
                'command_shape_summary' => [
                    'schema_version' => 'atlas.forge.rivals.cursor_command_shape_summary.v1',
                    'print_mode' => false,
                    'output_format_stream_json' => false,
                    'model_arg_present' => false,
                    'force_absent' => false,
                    'resume_absent' => false,
                    'prompt_arg_absent' => false,
                    'governed_cursor_cli_shape' => false,
                ],
                'test_log_hash' => null,
                'patch_diff_hash' => null,
                'output_format' => null,
                'stream_json_summary' => [
                    'schema_version' => 'atlas.forge.rivals.cursor_stream_json_receipt_summary.v1',
                    'parsed' => false,
                    'system_init_event' => false,
                    'system_init_api_key_source_present' => false,
                    'system_init_cwd_absolute' => false,
                    'system_init_model_present' => false,
                    'system_init_permission_mode_present' => false,
                    'user_message_event' => false,
                    'terminal_result_event' => false,
                    'terminal_result_success' => false,
                    'tool_event_count' => 0,
                    'tool_event_observed' => false,
                    'session_id_consistent' => false,
                    'parse_errors' => ['provider_receipt_not_supplied'],
                ],
            ];
        }
        $isFake = (bool) ($receipt['fake'] ?? false) || strtolower((string) ($receipt['mode'] ?? $mode)) === 'local_fake';
        $outputFormat = $this->resolveReceiptOutputFormat($receipt);

        return [
            'present' => true,
            'test_mode' => $isFake,
            'fake' => $isFake,
            'arm' => $receipt['arm'] ?? null,
            'mode' => $receipt['mode'] ?? null,
            'model' => $receipt['model'] ?? null,
            'exit_code' => $receipt['exit_code'] ?? null,
            'test_exit_code' => $receipt['test_exit_code'] ?? null,
            'killed' => (bool) ($receipt['killed'] ?? false),
            'timeout_reason' => $receipt['timeout_reason'] ?? null,
            'patch_diff_bytes' => (int) ($receipt['patch_diff_bytes'] ?? 0),
            'stdout_hash' => $receipt['stdout_hash'] ?? null,
            'stderr_hash' => $receipt['stderr_hash'] ?? null,
            'command_hash' => $receipt['command_hash'] ?? null,
            'prompt_hash' => $receipt['prompt_hash'] ?? null,
            'prompt_transport' => $receipt['prompt_transport'] ?? null,
            'stdin_prompt_hash' => $receipt['stdin_prompt_hash'] ?? null,
            'stdin_prompt_bytes' => $receipt['stdin_prompt_bytes'] ?? null,
            'command_shape_summary' => $this->summarizeCommandShape($receipt, $outputFormat),
            'test_log_hash' => $receipt['test_log_hash'] ?? null,
            'patch_diff_hash' => $receipt['patch_diff_hash'] ?? null,
            'output_format' => $outputFormat,
            'stream_json_summary' => $this->receiptStreamJsonSummary($receipt),
            'source' => $isFake ? 'local_fake_in_process_provider' : 'provider_process_runner',
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function summarizeCommandShape(array $receipt, ?string $outputFormat): array
    {
        $command = is_array($receipt['command'] ?? null)
            ? array_values(array_map(static fn (mixed $part): string => (string) $part, (array) $receipt['command']))
            : [];
        $modelIndex = array_search('--model', $command, true);
        $modelArgPresent = is_int($modelIndex)
            && isset($command[$modelIndex + 1])
            && trim((string) $command[$modelIndex + 1]) !== '';
        $promptTransport = (string) ($receipt['prompt_transport'] ?? '');
        $promptArgAbsent = $promptTransport === 'stdin'
            && $modelArgPresent
            && $modelIndex + 1 === array_key_last($command);
        $printMode = in_array('--print', $command, true) || in_array('-p', $command, true);
        $forceAbsent = ! in_array('--force', $command, true) && ! in_array('-f', $command, true);
        $resumeAbsent = ! in_array('--resume', $command, true);
        $outputFormatStreamJson = $outputFormat === 'stream-json';

        return [
            'schema_version' => 'atlas.forge.rivals.cursor_command_shape_summary.v1',
            'print_mode' => $printMode,
            'output_format_stream_json' => $outputFormatStreamJson,
            'model_arg_present' => $modelArgPresent,
            'force_absent' => $forceAbsent,
            'resume_absent' => $resumeAbsent,
            'prompt_arg_absent' => $promptArgAbsent,
            'governed_cursor_cli_shape' => $printMode
                && $outputFormatStreamJson
                && $modelArgPresent
                && $forceAbsent
                && $resumeAbsent
                && $promptArgAbsent,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function resolveReceiptOutputFormat(array $receipt): ?string
    {
        $explicit = strtolower(trim((string) ($receipt['output_format'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $command = is_array($receipt['command'] ?? null) ? array_values((array) $receipt['command']) : [];
        foreach ($command as $index => $part) {
            if ((string) $part === '--output-format') {
                $next = $command[$index + 1] ?? null;

                return is_string($next) && trim($next) !== '' ? strtolower(trim($next)) : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function receiptStreamJsonSummary(array $receipt): array
    {
        if (is_array($receipt['stream_json_summary'] ?? null)) {
            return (array) $receipt['stream_json_summary'];
        }

        $stdoutPath = (string) ($receipt['stdout_path'] ?? '');
        $payload = $stdoutPath !== '' && is_file($stdoutPath)
            ? (string) file_get_contents($stdoutPath)
            : (string) ($receipt['stdout_tail'] ?? '');

        return $this->summarizeStreamJsonPayload($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeStreamJsonPayload(string $payload): array
    {
        $systemInit = false;
        $systemInitApiKeySourcePresent = false;
        $systemInitCwdAbsolute = false;
        $systemInitModelPresent = false;
        $systemInitPermissionModePresent = false;
        $userMessage = false;
        $terminalResult = false;
        $terminalResultSuccess = false;
        $toolEvents = 0;
        $sessionIds = [];
        $parseErrors = [];

        foreach (preg_split('/\R/', trim($payload)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                $parseErrors[] = 'invalid_json_line';

                continue;
            }

            $type = (string) ($event['type'] ?? '');
            $subtype = (string) ($event['subtype'] ?? '');
            if (isset($event['session_id']) && is_string($event['session_id']) && trim($event['session_id']) !== '') {
                $sessionIds[] = trim($event['session_id']);
            }
            if ($type === 'system' && $subtype === 'init') {
                $systemInit = true;
                $systemInitApiKeySourcePresent = is_string($event['apiKeySource'] ?? null) && trim((string) $event['apiKeySource']) !== '';
                $cwd = (string) ($event['cwd'] ?? '');
                $systemInitCwdAbsolute = str_starts_with($cwd, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $cwd) === 1;
                $systemInitModelPresent = is_string($event['model'] ?? null) && trim((string) $event['model']) !== '';
                $systemInitPermissionModePresent = is_string($event['permissionMode'] ?? null) && trim((string) $event['permissionMode']) !== '';
            }
            if ($type === 'user') {
                $userMessage = true;
            }
            if ($type === 'result') {
                $terminalResult = true;
                $terminalResultSuccess = $subtype === 'success' && ($event['is_error'] ?? false) === false;
            }
            if (in_array($type, ['tool_call', 'tool_result'], true) || str_starts_with($type, 'tool_')) {
                $toolEvents++;
            }
        }

        $uniqueSessionIds = array_values(array_unique($sessionIds));

        return [
            'schema_version' => 'atlas.forge.rivals.cursor_stream_json_receipt_summary.v1',
            'parsed' => $payload !== '' && $parseErrors === [],
            'system_init_event' => $systemInit,
            'system_init_api_key_source_present' => $systemInitApiKeySourcePresent,
            'system_init_cwd_absolute' => $systemInitCwdAbsolute,
            'system_init_model_present' => $systemInitModelPresent,
            'system_init_permission_mode_present' => $systemInitPermissionModePresent,
            'user_message_event' => $userMessage,
            'terminal_result_event' => $terminalResult,
            'terminal_result_success' => $terminalResultSuccess,
            'tool_event_count' => $toolEvents,
            'tool_event_observed' => $toolEvents > 0,
            'session_id_consistent' => count($uniqueSessionIds) === 1,
            'session_id_hash' => count($uniqueSessionIds) === 1 ? hash('sha256', $uniqueSessionIds[0]) : null,
            'parse_errors' => array_values(array_unique($parseErrors)),
        ];
    }

    /**
     * Surface after-clean-check details that the operator harness recorded.
     * Three signals are merged into a single object: (a) workspace_hashes.json
     * (`dirty_after_run`, `workspace_blockers`), (b) the run manifest
     * (`dirty_after_run`, `workspace_blockers`), and (c) per-arm receipts
     * (`workspace_has_blocking_changes`, `bytecode_artifacts`, `changed_files`).
     *
     * The verifier reads `clean=true` to admit a real_run.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $workspaceHashes
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return array<string,mixed>
     */
    private function summarizeAfterCleanCheck(array $manifest, array $workspaceHashes, array $atlasReceipt, array $rivalReceipt): array
    {
        $manifestDirty = $manifest['dirty_after_run'] ?? null;
        $workspaceDirty = $workspaceHashes['dirty_after_run'] ?? null;
        $dirty = $manifestDirty === true || $workspaceDirty === true;
        $ran = $manifest !== [] || $workspaceHashes !== [];
        $clean = $ran ? ! $dirty : null;
        $blockers = array_values(array_unique(array_merge(
            $this->stringList($manifest['workspace_blockers'] ?? []),
            $this->stringList($workspaceHashes['workspace_blockers'] ?? []),
        )));
        $atlasBlocking = (bool) ($atlasReceipt['workspace_has_blocking_changes'] ?? false);
        $rivalBlocking = (bool) ($rivalReceipt['workspace_has_blocking_changes'] ?? false);
        $changedFiles = [
            'atlas' => $this->stringList($atlasReceipt['changed_files'] ?? []),
            'rival' => $this->stringList($rivalReceipt['changed_files'] ?? []),
        ];

        return [
            'ran' => $ran,
            'clean' => $clean,
            'dirty_after_run' => $dirty,
            'workspace_blockers' => $blockers,
            'arm_blocking_changes' => [
                'atlas' => $atlasBlocking,
                'rival' => $rivalBlocking,
            ],
            'changed_files' => $changedFiles,
            'head_changed' => null,
            'source' => $ran ? 'manifest+workspace_hashes+arm_receipts' : 'not_recorded',
            'reason_not_run' => $ran ? null : 'no_run_manifest_or_workspace_hashes_on_disk',
        ];
    }

    /**
     * Aggregate every bytecode artifact across arms. Any tracked .pyc/.pyo or
     * __pycache__ path is a terminal blocker for the run, regardless of mode.
     *
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @return list<string>
     */
    private function aggregateBytecodeArtifacts(array $atlasReceipt, array $rivalReceipt): array
    {
        $atlas = $this->stringList($atlasReceipt['bytecode_artifacts'] ?? []);
        $rival = $this->stringList($rivalReceipt['bytecode_artifacts'] ?? []);

        return array_values(array_unique(array_merge($atlas, $rival)));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function metaProviderEvidenceContract(array $manifest): array
    {
        $contracts = is_array($manifest['arena_contracts'] ?? null) ? (array) $manifest['arena_contracts'] : [];
        $arms = [];
        foreach (['arm_a' => 'atlas', 'arm_b' => 'rival'] as $role => $legacyArm) {
            $contract = is_array($contracts[$role] ?? null) ? (array) $contracts[$role] : [];
            if ((bool) ($contract['meta_provider'] ?? false) !== true) {
                continue;
            }
            $providerMetadata = is_array($contract['provider_metadata'] ?? null) ? (array) $contract['provider_metadata'] : [];
            $arms[$legacyArm] = [
                'role' => $role,
                'arm_id' => $contract['arm_id'] ?? null,
                'provider' => $contract['provider'] ?? null,
                'provider_kind' => $contract['provider_kind'] ?? null,
                'meta_provider_parent' => $contract['meta_provider_parent'] ?? null,
                'tool_event_stream' => $providerMetadata['tool_event_stream'] ?? null,
                'required_receipt_fields' => ['model', 'command_hash', 'prompt_hash', 'prompt_transport', 'stdin_prompt_hash', 'stdout_hash', 'exit_code'],
                'required_stream_json_signals' => [
                    'output_format_stream_json',
                    'system_init_event',
                    'system_init_api_key_source_present',
                    'system_init_cwd_absolute',
                    'system_init_model_present',
                    'system_init_permission_mode_present',
                    'user_message_event',
                    'terminal_result_event',
                    'terminal_result_success',
                    'tool_event_observed',
                    'governed_cursor_cli_command_shape',
                    'session_id_consistent',
                ],
                'requires_human_prompt_hash' => true,
                'requires_context_profile' => true,
                'requires_human_prompt_probe' => true,
                'requires_complexity_profile' => true,
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.meta_provider_evidence_contract.v1',
            'applies' => $arms !== [],
            'arms' => $arms,
            'required_case_fields' => ['human_prompt_hash', 'context_profile', 'measurement_tags', 'human_prompt_probe', 'complexity_profile'],
            'claim_effect' => 'blocks_score_without_receipts_and_prompt_context',
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * Translate the run's operator mode into a stable evidence-mode string
     * the verifier consumes. `fair` and `full_power` both produce real_run
     * evidence; `local_fake` produces fake_run; absence means dry_run.
     *
     * @param  array<string,mixed>  $manifest
     */
    private function resolveEvidenceMode(array $manifest): string
    {
        $mode = strtolower((string) ($manifest['mode'] ?? ''));

        return match ($mode) {
            'fair', 'full_power', 'power' => self::EVIDENCE_MODE_REAL_RUN,
            'local_fake' => self::EVIDENCE_MODE_FAKE_RUN,
            'diagnostic', 'replay_only', 'dry_run' => self::EVIDENCE_MODE_DRY_RUN,
            '' => self::EVIDENCE_MODE_UNKNOWN,
            default => self::EVIDENCE_MODE_UNKNOWN,
        };
    }

    /**
     * Build the sidecar artifact index for a (run_id, stage) pair. The verifier
     * and the CLI `evidence`/`replay`/`verify-evidence` actions consume this
     * file as the single source of truth for "what artifacts exist on disk and
     * what is their sha256". The index is intentionally flat — one entry per
     * artifact key — so CI tooling can diff it across runs without parsing the
     * full evidence pack.
     *
     * Schema: `atlas.forge.rivals.artifact_index.v1`.
     *
     * @param  array<string,mixed>  $artifacts
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function buildArtifactIndex(string $runId, string $stage, array $artifacts, array $plan): array
    {
        $entries = [];
        foreach ($artifacts as $key => $desc) {
            $entries[$key] = [
                'path' => $desc['path'] ?? null,
                'present' => (bool) ($desc['present'] ?? false),
                'bytes' => (int) ($desc['bytes'] ?? 0),
                'sha256' => $desc['sha256'] ?? null,
                'policy' => $desc['policy'] ?? AtlasForgeRivalsEvidencePolicy::POLICY_OPTIONAL,
                'reason_missing' => $desc['reason_missing'] ?? null,
            ];
        }

        return [
            'schema_version' => self::ARTIFACT_INDEX_SCHEMA_VERSION,
            'run_id' => $runId,
            'evidence_stage' => $stage,
            'generated_at' => now()->toJSON(),
            'required_artifacts' => $plan['required'],
            'optional_artifacts' => $plan['optional'],
            'artifacts' => $entries,
            'external_rivals_certification_status' => 'blocked',
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return AiStringListNormalizer::castItemsToStrings($value);
    }

    /**
     * Build the multi-case summary surfaced on the evidence pack. Walks the
     * run manifest's `cases[]` array (written by run-real) and produces three
     * blocks the verifier consumes:
     *
     *   - `cases`: per-case digest with case_id, task_category, difficulty,
     *     difficulty_level (L1-L5), verdict, evidence_subdir, per-arm patch /
     *     test log paths + sha256 hashes. `difficulty_level` carries an
     *     `origin` (`corpus`/`auto_mapped_from_legacy`/`missing`) so the
     *     verifier can fail-closed when difficulty is absent.
     *   - `category_summary`: counts by `task_category`.
     *   - `difficulty_summary`: counts by L1-L5 level plus the canonical
     *     ladder so a battery that runs only L1 cases never gets pretended as
     *     a release-grade L5 score.
     *
     * Returns `is_multi_case=false` and zero-length lists for legacy single
     * case runs that did not write a `cases[]` array.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,string>  $paths
     * @return array{
     *   is_multi_case: bool,
     *   case_count: int,
     *   cases: list<array<string,mixed>>,
     *   category_summary: array<string,mixed>,
     *   difficulty_summary: array<string,mixed>,
     * }
     */
    private function buildMultiCaseSummary(array $manifest, array $paths): array
    {
        $manifestCases = $manifest['cases'] ?? null;
        $isMultiCase = (bool) ($manifest['is_multi_case'] ?? false);
        if (! is_array($manifestCases) || $manifestCases === []) {
            return [
                'is_multi_case' => false,
                'case_count' => 0,
                'cases' => [],
                'category_summary' => [
                    'counts' => [],
                    'present_categories' => [],
                ],
                'difficulty_summary' => [
                    'counts' => array_fill_keys(AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, 0),
                    'missing_difficulty_count' => 0,
                    'ladder' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS,
                    'weights' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_SCORE_WEIGHTS,
                ],
            ];
        }

        $cases = [];
        $categoryCounts = [];
        $difficultyCounts = array_fill_keys(AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, 0);
        $missingDifficultyCount = 0;
        $totalWeight = 0.0;

        foreach ($manifestCases as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $caseId = (string) ($entry['case_id'] ?? '');
            if ($caseId === '') {
                continue;
            }

            $taskCategory = isset($entry['task_category']) ? (string) $entry['task_category'] : '';
            if ($taskCategory !== '') {
                $categoryCounts[$taskCategory] = ($categoryCounts[$taskCategory] ?? 0) + 1;
            }

            $rawLevel = isset($entry['difficulty_level']) ? (string) $entry['difficulty_level'] : '';
            $legacyDifficulty = isset($entry['difficulty']) ? (string) $entry['difficulty'] : '';
            [$level, $origin] = $this->resolveDifficultyLevel($rawLevel, $legacyDifficulty);
            if ($level === null) {
                $missingDifficultyCount++;
            } else {
                $difficultyCounts[$level]++;
                $totalWeight += AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level);
            }
            $weight = $level === null ? null : AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level);

            $evidenceSubdir = isset($entry['evidence_subdir']) && is_string($entry['evidence_subdir']) && trim($entry['evidence_subdir']) !== ''
                ? $paths['evidence'].'/'.$entry['evidence_subdir']
                : $paths['evidence'];

            $cases[] = [
                'case_id' => $caseId,
                'case_index' => (int) ($entry['case_index'] ?? 0),
                'task_category' => $taskCategory !== '' ? $taskCategory : null,
                'case_source' => (string) ($entry['case_source'] ?? 'legacy'),
                'case_set' => $entry['case_set'] ?? null,
                'human_prompt_hash' => $entry['human_prompt_hash'] ?? null,
                'context_profile' => $entry['context_profile'] ?? null,
                'measurement_tags' => $entry['measurement_tags'] ?? [],
                'human_prompt_probe' => $entry['human_prompt_probe'] ?? null,
                'meta_provider_stress' => $entry['meta_provider_stress'] ?? null,
                'extreme_differentiator' => $entry['extreme_differentiator'] ?? null,
                'measured_capabilities' => $this->stringList($entry['measured_capabilities'] ?? []),
                'difficulty' => $legacyDifficulty !== '' ? $legacyDifficulty : null,
                'difficulty_level' => $level,
                'difficulty_level_origin' => $origin,
                'difficulty_weight' => $weight,
                'verdict' => (string) ($entry['verdict'] ?? 'unknown'),
                'evidence_subdir' => $entry['evidence_subdir'] ?? null,
                'evidence_path' => $evidenceSubdir,
                'workspace_hash_before' => $entry['workspace_hash_before'] ?? null,
                'workspace_hash_after' => $entry['workspace_hash_after'] ?? null,
                'workspace_blockers' => $this->stringList($entry['workspace_blockers'] ?? []),
                'arms' => [
                    'atlas' => $this->summarizeCaseArm((array) ($entry['atlas_arm'] ?? []), $evidenceSubdir, 'atlas'),
                    'rival' => $this->summarizeCaseArm((array) ($entry['rival_arm'] ?? []), $evidenceSubdir, 'rival'),
                ],
            ];
        }

        return [
            'is_multi_case' => $isMultiCase || count($cases) > 1,
            'case_count' => count($cases),
            'cases' => $cases,
            'category_summary' => [
                'counts' => $categoryCounts,
                'present_categories' => array_keys($categoryCounts),
            ],
            'difficulty_summary' => [
                'counts' => $difficultyCounts,
                'missing_difficulty_count' => $missingDifficultyCount,
                'ladder' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS,
                'weights' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_SCORE_WEIGHTS,
                'total_weight' => $totalWeight,
            ],
        ];
    }

    /**
     * Resolve the canonical L1-L5 difficulty level for a case entry. Returns
     * `[level, origin]` where `level` is null when difficulty cannot be
     * resolved from either the new canonical field or the legacy
     * easy/medium/hard fallback. The verifier rejects the pack when this is
     * the case.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveDifficultyLevel(string $rawLevel, string $legacyDifficulty): array
    {
        $upper = strtoupper(trim($rawLevel));
        if (in_array($upper, AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, true)) {
            return [$upper, 'corpus'];
        }
        $lower = strtolower(trim($legacyDifficulty));
        if (isset(AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_TO_LEVEL[$lower])) {
            return [
                AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_TO_LEVEL[$lower],
                'auto_mapped_from_legacy',
            ];
        }

        return [null, 'missing'];
    }

    /**
     * @param  array<string,mixed>  $armSummary
     * @return array<string,mixed>
     */
    private function summarizeCaseArm(array $armSummary, string $evidencePath, string $armKey): array
    {
        if ($armSummary === []) {
            return [
                'present' => false,
                'reason_missing' => 'arm_summary_not_recorded_in_manifest:'.$armKey,
            ];
        }

        // Per-case provider receipt presence is decided by disk, not by the
        // manifest snapshot. A missing receipt file under cases/<subdir>/
        // means the case ran without a recordable provider receipt and must
        // not be trusted as a real_run.
        $receiptPath = $evidencePath.'/'.$armKey.'_receipt.json';
        $receiptPresent = is_file($receiptPath);
        if (! $receiptPresent) {
            return [
                'present' => false,
                'reason_missing' => 'provider_receipt_missing_on_disk:'.$armKey,
                'receipt_path' => $receiptPath,
            ];
        }

        $patchPath = $evidencePath.'/'.$armKey.'_patch.diff';
        $testLogPath = $evidencePath.'/'.$armKey.'_test.log';

        return [
            'present' => true,
            'receipt_path' => $receiptPath,
            'receipt_on_disk_sha256' => hash_file('sha256', $receiptPath) ?: null,
            'exit_code' => $armSummary['exit_code'] ?? null,
            'test_exit_code' => $armSummary['test_exit_code'] ?? null,
            'killed' => (bool) ($armSummary['killed'] ?? false),
            'timeout_reason' => $armSummary['timeout_reason'] ?? null,
            'patch_diff_bytes' => (int) ($armSummary['patch_diff_bytes'] ?? 0),
            'patch_diff_hash' => $armSummary['patch_diff_hash'] ?? null,
            'patch_diff_path' => is_file($patchPath) ? $patchPath : ($armSummary['patch_diff_path'] ?? null),
            'patch_diff_on_disk_sha256' => is_file($patchPath) ? (hash_file('sha256', $patchPath) ?: null) : null,
            'test_log_path' => is_file($testLogPath) ? $testLogPath : ($armSummary['test_log_path'] ?? null),
            'test_log_on_disk_sha256' => is_file($testLogPath) ? (hash_file('sha256', $testLogPath) ?: null) : null,
            'changed_files' => $this->stringList($armSummary['changed_files'] ?? []),
            'out_of_scope_files' => $this->stringList($armSummary['out_of_scope_files'] ?? []),
            'bytecode_artifacts' => $this->stringList($armSummary['bytecode_artifacts'] ?? []),
        ];
    }
}

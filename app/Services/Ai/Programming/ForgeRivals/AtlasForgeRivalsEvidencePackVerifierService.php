<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\JsonFileStore;

/**
 * Atlas Forge Rivals · Evidence Pack Verifier (v2 hardened).
 *
 * Read-only contract enforcer that admits or rejects a run's evidence pack
 * as a basis for a Rivals score / claim. Reads `evidence_pack.json` and
 * `artifact_index.json` from disk, re-hashes every artifact the pack
 * declared `present=true`, replays the replay manifest, and applies the
 * strict mode-specific checks documented in
 * `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`.
 *
 * Four canonical modes:
 *   - `dry_run`: planning-only. Only `manifest`, `events_jsonl`, `intent_json`
 *     and `replay_manifest` are required. Provider receipts are NEVER required
 *     and `real_run`/`fake_run` flags must be false.
 *   - `fake_run`: in-process fake provider. Accepts a receipt that carries the
 *     `test_mode=true` / `fake=true` markers; real provider receipts are
 *     rejected (a real receipt should be verified under real_run).
 *   - `real_run`: external provider was invoked. Hardest contract: provider
 *     receipts must be present, not flagged `test_mode`, and the run must
 *     ship every per-arm artifact (patch diff, test log, workspace hashes
 *     before/after, clean after-check). A fake receipt or any missing field
 *     => `invalid_missing_evidence_for_real_run`.
 *   - `replay`: integrity-only. Verifies that every artifact the pack listed
 *     `present=true` still matches its on-disk sha256, and that the artifact
 *     index, replay manifest and events.jsonl have not drifted.
 *
 * Every verification result carries `claim_ready=false`. Even when the pack
 * itself is verified clean, that proves the audit trail is real; it never
 * promotes the claim. `external_rivals_certification` is always reported
 * `blocked`. No provider call is ever issued.
 *
 * Schema: `atlas.forge.rivals.evidence_verification.v2`.
 */
final class AtlasForgeRivalsEvidencePackVerifierService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.evidence_verification.v2';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_FAKE_RUN = 'fake_run';

    public const MODE_REAL_RUN = 'real_run';

    public const MODE_REPLAY = 'replay';

    /** @var list<string> Modes the verifier admits. */
    public const ALL_MODES = [
        self::MODE_DRY_RUN,
        self::MODE_FAKE_RUN,
        self::MODE_REAL_RUN,
        self::MODE_REPLAY,
    ];

    /** Always-required artifact keys across every mode. */
    private const ALWAYS_REQUIRED_KEYS = ['manifest', 'events_jsonl'];

    /**
     * @return array<int,string>
     */
    public static function canonicalContextRefPaths(): array
    {
        return array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            self::canonicalContextRefDefinitions(),
        ));
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-evidence-pack-replay-hardening-v2.md', 'kind' => 'canonical_doc', 'reason' => 'Contrato v2 hardened para verifier modes, receipts e replay integrity.'],
            ['path' => 'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierService.php', 'kind' => 'service_implementation', 'reason' => 'Verifier read-only que admite ou rejeita evidence packs por mode.'],
            ['path' => 'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Testes unitarios focados (normalizeMode, refs canonicas e fail-closed run_id).'],
            ['path' => 'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php', 'kind' => 'test_evidence', 'reason' => 'Suite v2 que prova dry_run, fake_run, real_run e replay end-to-end.'],
        ];
    }

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsReplayService $replay,
    ) {}

    /**
     * Normalize an operator-supplied --mode value to one of the four canonical
     * verifier modes. Defaults to `real_run` for the briefing-canon CLI form
     * `verify-evidence --mode=real_run`. Empty or unknown values fall back to
     * `replay` so the strictness is honest: we do integrity checks but never
     * silently pretend a real_run was verified.
     */
    public static function normalizeMode(?string $mode): string
    {
        return AtlasForgeRivalsInputNormalizer::evidenceVerificationMode($mode);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return $this->emit(self::normalizeMode($input['mode'] ?? null), null, ['run_id_required'], [], [], null);
        }

        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return $this->emit(self::normalizeMode($input['mode'] ?? null), $paths['run_id'], ['run_not_found:'.$paths['run_id']], [], [], null);
        }

        $mode = self::normalizeMode($input['mode'] ?? null);
        $stage = AtlasForgeRivalsEvidencePolicy::normalizeStage((string) ($input['evidence_stage'] ?? ''));

        $packPath = $paths['evidence'].'/evidence_pack.json';
        $indexPath = $paths['evidence'].'/artifact_index.json';
        $manifestPath = $paths['manifest_json'];
        $eventsPath = $paths['events_jsonl'];

        if (! is_file($packPath)) {
            return $this->emit($mode, $paths['run_id'], ['evidence_pack_missing:'.$packPath], [], [], null);
        }
        $pack = $this->readJson($packPath);
        $index = is_file($indexPath) ? $this->readJson($indexPath) : null;

        $blockers = [];
        $invalidReasons = [];
        $missingEvidence = [];

        $schema = (string) ($pack['schema_version'] ?? '');
        if (
            $schema !== AtlasForgeRivalsCollectEvidenceService::SCHEMA_VERSION
            && $schema !== AtlasForgeRivalsCollectEvidenceService::SCHEMA_VERSION_LEGACY
        ) {
            $blockers[] = 'invalid_schema_version:'.$schema;
            $invalidReasons[] = 'schema_version_not_recognized';
        }
        if (($pack['run_id'] ?? null) !== null && $pack['run_id'] !== $paths['run_id']) {
            $blockers[] = 'run_id_mismatch:'.$pack['run_id'].'!='.$paths['run_id'];
            $invalidReasons[] = 'run_id_mismatch';
        }
        if (($pack['promotes_external_rivals_claim'] ?? false) === true) {
            $blockers[] = 'promotes_external_rivals_claim_flag_must_be_false';
            $invalidReasons[] = 'external_rivals_claim_flag_set';
        }
        if (($pack['claim_ready'] ?? false) === true) {
            $blockers[] = 'claim_ready_must_remain_false_in_evidence_pack';
            $invalidReasons[] = 'claim_ready_must_remain_false';
        }

        $artifacts = (array) ($pack['artifacts'] ?? []);
        $required = $this->stringList($pack['required_artifacts'] ?? []);
        if ($required === []) {
            $required = array_keys($artifacts);
        }

        // 1) Every present=false artifact must carry reason_missing.
        foreach ($artifacts as $key => $desc) {
            if (! is_array($desc)) {
                continue;
            }
            $present = (bool) ($desc['present'] ?? false);
            if (! $present) {
                $reason = $desc['reason_missing'] ?? null;
                if (! is_string($reason) || trim($reason) === '') {
                    $blockers[] = 'absent_without_reason_missing:'.$key;
                    $invalidReasons[] = 'absent_artifact_'.$key.'_without_reason_missing';
                }
            }
        }

        // 2) Re-hash every present=true artifact and compare to the pack.
        foreach ($artifacts as $key => $desc) {
            if (! is_array($desc) || ! ($desc['present'] ?? false)) {
                continue;
            }
            $path = (string) ($desc['path'] ?? '');
            $expected = (string) ($desc['sha256'] ?? '');
            if (! is_file($path)) {
                $blockers[] = 'artifact_missing_at_verify:'.$key;
                $invalidReasons[] = 'artifact_missing_at_verify_'.$key;

                continue;
            }
            $actual = hash_file('sha256', $path) ?: '';
            if ($expected !== '' && $expected !== $actual) {
                $blockers[] = 'artifact_hash_mismatch:'.$key;
                $invalidReasons[] = 'artifact_hash_mismatch_'.$key;
            }
        }

        // 3) Always-required keys must be present regardless of stage / mode.
        foreach (self::ALWAYS_REQUIRED_KEYS as $key) {
            $desc = $artifacts[$key] ?? null;
            if (! is_array($desc) || ! ($desc['present'] ?? false)) {
                $blockers[] = 'missing_always_required:'.$key;
                $missingEvidence[] = $key;
            }
        }

        // 4) Replay manifest hash must round-trip through the replay service.
        if (! is_file($eventsPath)) {
            $blockers[] = 'events_jsonl_missing_on_disk';
            $missingEvidence[] = 'events_jsonl';
        }
        if (! is_file($manifestPath)) {
            $blockers[] = 'manifest_missing_on_disk';
            $missingEvidence[] = 'manifest';
        }
        $replayResult = $this->replay->replay([
            'run_id' => $paths['run_id'],
            'evidence_stage' => $stage,
        ]);
        $replayPasses = (bool) ($replayResult['replay_passes'] ?? false);
        if (! $replayPasses && $mode !== self::MODE_DRY_RUN) {
            // dry_run is allowed to fail replay (pack may declare optional-only
            // artifacts; verifier surfaces it as a warning instead of a blocker).
            $blockers[] = 'replay_failed';
            $invalidReasons[] = 'replay_failed';
            foreach ((array) ($replayResult['required_mismatches'] ?? []) as $m) {
                $invalidReasons[] = 'required_mismatch:'.$m;
            }
            foreach ((array) ($replayResult['hash_mismatches'] ?? []) as $m) {
                $invalidReasons[] = 'hash_mismatch:'.$m;
            }
        }

        // 5) Artifact index drift check (sidecar evolved separately is suspicious).
        if ($index !== null) {
            $indexSchema = (string) ($index['schema_version'] ?? '');
            if ($indexSchema !== AtlasForgeRivalsCollectEvidenceService::ARTIFACT_INDEX_SCHEMA_VERSION) {
                $blockers[] = 'artifact_index_schema_version_invalid:'.$indexSchema;
                $invalidReasons[] = 'artifact_index_schema_drift';
            }
        }

        // 6) Mode-specific strict checks.
        $modeReport = $this->applyModeRules($mode, $pack, $paths);
        $blockers = array_merge($blockers, $modeReport['blockers']);
        $invalidReasons = array_merge($invalidReasons, $modeReport['invalid_reasons']);
        $missingEvidence = array_merge($missingEvidence, $modeReport['missing_evidence']);

        $metaProviderReport = $this->applyMetaProviderEvidenceRules($mode, $pack);
        $blockers = array_merge($blockers, $metaProviderReport['blockers']);
        $invalidReasons = array_merge($invalidReasons, $metaProviderReport['invalid_reasons']);
        $missingEvidence = array_merge($missingEvidence, $metaProviderReport['missing_evidence']);

        $blockers = array_values(array_unique($blockers));
        $invalidReasons = array_values(array_unique($invalidReasons));
        $missingEvidence = array_values(array_unique($missingEvidence));

        return $this->emit($mode, $paths['run_id'], $blockers, $invalidReasons, $missingEvidence, [
            'pack' => $pack,
            'artifact_index' => $index,
            'replay' => $replayResult,
            'evidence_stage' => $stage,
        ]);
    }

    /**
     * Apply the mode-specific strict checks.
     *
     * @param  array<string,mixed>  $pack
     * @param  array<string,string>  $paths
     * @return array{blockers:list<string>,invalid_reasons:list<string>,missing_evidence:list<string>}
     */
    private function applyModeRules(string $mode, array $pack, array $paths): array
    {
        $blockers = [];
        $reasons = [];
        $missing = [];

        $providerReceipts = (array) ($pack['provider_receipts'] ?? []);
        $afterClean = (array) ($pack['after_clean_check'] ?? []);
        $bytecode = $this->stringList($pack['tracked_bytecode_artifacts'] ?? []);
        $modeForEvidence = (string) ($pack['mode_for_evidence'] ?? AtlasForgeRivalsCollectEvidenceService::EVIDENCE_MODE_UNKNOWN);

        if ($bytecode !== []) {
            // Bytecode is terminal for every mode except pure dry_run.
            if ($mode !== self::MODE_DRY_RUN) {
                $blockers[] = 'tracked_python_bytecode_present';
                $reasons[] = 'tracked_python_bytecode_present:'.implode(',', array_slice($bytecode, 0, 5));
            }
        }

        if ($mode === self::MODE_DRY_RUN) {
            // dry_run: no provider receipts allowed to be declared `present=true`
            // with a non-test mode marker. external_provider_call must be false.
            if (($pack['external_provider_call'] ?? false) === true) {
                $blockers[] = 'dry_run_pack_must_not_declare_external_provider_call';
                $reasons[] = 'dry_run_with_external_provider_call_true';
            }
            $atlas = (array) ($providerReceipts['atlas'] ?? []);
            $rival = (array) ($providerReceipts['rival'] ?? []);
            if (($atlas['present'] ?? false) === true && ($atlas['test_mode'] ?? false) !== true) {
                $blockers[] = 'dry_run_must_not_carry_real_provider_receipt:atlas';
                $reasons[] = 'dry_run_with_real_provider_receipt_atlas';
            }
            if (($rival['present'] ?? false) === true && ($rival['test_mode'] ?? false) !== true) {
                $blockers[] = 'dry_run_must_not_carry_real_provider_receipt:rival';
                $reasons[] = 'dry_run_with_real_provider_receipt_rival';
            }
        }

        if ($mode === self::MODE_FAKE_RUN) {
            $atlas = (array) ($providerReceipts['atlas'] ?? []);
            $rival = (array) ($providerReceipts['rival'] ?? []);
            foreach (['atlas' => $atlas, 'rival' => $rival] as $armKey => $receipt) {
                if (($receipt['present'] ?? false) !== true) {
                    $blockers[] = 'fake_run_provider_receipt_missing:'.$armKey;
                    $missing[] = 'provider_receipt:'.$armKey;
                    $reasons[] = 'fake_run_provider_receipt_missing_'.$armKey;

                    continue;
                }
                if (($receipt['test_mode'] ?? false) !== true) {
                    $blockers[] = 'fake_run_receipt_not_marked_test_mode:'.$armKey;
                    $reasons[] = 'fake_run_receipt_not_marked_test_mode_'.$armKey;
                }
            }
            if (($pack['external_provider_call'] ?? false) === true) {
                $blockers[] = 'fake_run_pack_must_not_declare_external_provider_call';
                $reasons[] = 'fake_run_with_external_provider_call_true';
            }
        }

        if ($mode === self::MODE_REAL_RUN) {
            if ($modeForEvidence !== AtlasForgeRivalsCollectEvidenceService::EVIDENCE_MODE_REAL_RUN) {
                $blockers[] = 'real_run_evidence_mode_mismatch:'.$modeForEvidence;
                $reasons[] = 'real_run_evidence_mode_mismatch';
            }
            if (($pack['external_provider_call'] ?? false) !== true) {
                $blockers[] = 'real_run_pack_must_declare_external_provider_call_true';
                $reasons[] = 'real_run_pack_must_declare_external_provider_call_true';
            }

            foreach (['atlas', 'rival'] as $armKey) {
                $receipt = (array) ($providerReceipts[$armKey] ?? []);
                if (($receipt['present'] ?? false) !== true) {
                    $blockers[] = 'real_run_provider_receipt_missing:'.$armKey;
                    $missing[] = 'provider_receipt:'.$armKey;
                    $reasons[] = 'real_run_provider_receipt_missing_'.$armKey;

                    continue;
                }
                if (($receipt['test_mode'] ?? false) === true || ($receipt['fake'] ?? false) === true) {
                    $blockers[] = 'real_run_rejects_fake_receipt:'.$armKey;
                    $reasons[] = 'real_run_rejects_fake_receipt_'.$armKey;
                }
            }

            $perArmRequired = [
                'atlas_patch' => 'patch_diff:atlas',
                'rival_patch' => 'patch_diff:rival',
                'atlas_test_log' => 'test_log:atlas',
                'rival_test_log' => 'test_log:rival',
                'workspace_hashes' => 'workspace_hashes',
            ];
            $artifacts = (array) ($pack['artifacts'] ?? []);
            foreach ($perArmRequired as $key => $semantic) {
                $desc = $artifacts[$key] ?? null;
                if (! is_array($desc) || ! ($desc['present'] ?? false)) {
                    $blockers[] = 'real_run_missing_required_artifact:'.$semantic;
                    $missing[] = $semantic;
                    $reasons[] = 'real_run_missing_required_artifact_'.$semantic;
                }
            }

            if (($afterClean['ran'] ?? false) !== true) {
                $blockers[] = 'real_run_after_clean_check_not_run';
                $reasons[] = 'real_run_after_clean_check_not_run';
            } elseif (($afterClean['clean'] ?? null) !== true) {
                $blockers[] = 'real_run_after_clean_check_dirty';
                $reasons[] = 'real_run_after_clean_check_dirty';
            }

            $beforeHashes = (array) ($pack['workspace_hash_before'] ?? []);
            $afterHashes = (array) ($pack['workspace_hash_after'] ?? []);
            foreach (['atlas', 'rival'] as $armKey) {
                if (! isset($beforeHashes[$armKey]) || ! is_string($beforeHashes[$armKey]) || trim($beforeHashes[$armKey]) === '') {
                    $blockers[] = 'real_run_workspace_hash_before_missing:'.$armKey;
                    $missing[] = 'workspace_hash_before:'.$armKey;
                    $reasons[] = 'real_run_workspace_hash_before_missing_'.$armKey;
                }
                if (! isset($afterHashes[$armKey]) || ! is_string($afterHashes[$armKey]) || trim($afterHashes[$armKey]) === '') {
                    $blockers[] = 'real_run_workspace_hash_after_missing:'.$armKey;
                    $missing[] = 'workspace_hash_after:'.$armKey;
                    $reasons[] = 'real_run_workspace_hash_after_missing_'.$armKey;
                }
            }
        }

        if ($mode === self::MODE_REPLAY) {
            // Replay mode: integrity only. The shared hash/replay loop above
            // already enforced present=true sha256 matching. We additionally
            // require that the pack carries provider receipts when it claimed
            // external_provider_call=true so a tampered "downgrade" can't sneak
            // through.
            if (($pack['external_provider_call'] ?? false) === true) {
                foreach (['atlas', 'rival'] as $armKey) {
                    $receipt = (array) ($providerReceipts[$armKey] ?? []);
                    if (($receipt['present'] ?? false) !== true) {
                        $blockers[] = 'replay_provider_receipt_missing_for_real_pack:'.$armKey;
                        $missing[] = 'provider_receipt:'.$armKey;
                        $reasons[] = 'replay_provider_receipt_missing_for_real_pack_'.$armKey;
                    }
                }
            }
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'invalid_reasons' => array_values(array_unique($reasons)),
            'missing_evidence' => array_values(array_unique($missing)),
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array{blockers:list<string>,invalid_reasons:list<string>,missing_evidence:list<string>}
     */
    private function applyMetaProviderEvidenceRules(string $mode, array $pack): array
    {
        $contract = is_array($pack['meta_provider_evidence_contract'] ?? null)
            ? (array) $pack['meta_provider_evidence_contract']
            : [];
        if (($contract['applies'] ?? false) !== true) {
            return ['blockers' => [], 'invalid_reasons' => [], 'missing_evidence' => []];
        }

        $blockers = [];
        $reasons = [];
        $missing = [];
        $providerReceipts = is_array($pack['provider_receipts'] ?? null) ? (array) $pack['provider_receipts'] : [];
        foreach ((array) ($contract['arms'] ?? []) as $armKey => $armContract) {
            if (! is_array($armContract)) {
                continue;
            }
            $receipt = is_array($providerReceipts[$armKey] ?? null) ? (array) $providerReceipts[$armKey] : [];
            if (($receipt['present'] ?? false) !== true) {
                if ($mode !== self::MODE_DRY_RUN) {
                    $blockers[] = 'meta_provider_receipt_missing:'.$armKey;
                    $missing[] = 'meta_provider_receipt:'.$armKey;
                    $reasons[] = 'meta_provider_receipt_missing_'.$armKey;
                }

                continue;
            }
            foreach ((array) ($armContract['required_receipt_fields'] ?? []) as $field) {
                $field = (string) $field;
                $value = $receipt[$field] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    $blockers[] = 'meta_provider_receipt_field_missing:'.$armKey.':'.$field;
                    $missing[] = 'meta_provider_receipt.'.$armKey.'.'.$field;
                    $reasons[] = 'meta_provider_receipt_field_missing_'.$armKey.'_'.$field;
                }
            }
            if (($armContract['tool_event_stream'] ?? null) === 'stream-json' && $mode !== self::MODE_DRY_RUN) {
                if (($receipt['prompt_transport'] ?? null) !== 'stdin') {
                    $blockers[] = 'meta_provider_receipt_prompt_transport_not_stdin:'.$armKey;
                    $missing[] = 'meta_provider_receipt.'.$armKey.'.prompt_transport_stdin';
                    $reasons[] = 'meta_provider_receipt_prompt_transport_not_stdin_'.$armKey;
                }
                $stdinPromptHash = $receipt['stdin_prompt_hash'] ?? null;
                if (! is_string($stdinPromptHash) || preg_match('/^[a-f0-9]{64}$/', $stdinPromptHash) !== 1) {
                    $blockers[] = 'meta_provider_receipt_stdin_prompt_hash_invalid:'.$armKey;
                    $missing[] = 'meta_provider_receipt.'.$armKey.'.stdin_prompt_hash';
                    $reasons[] = 'meta_provider_receipt_stdin_prompt_hash_invalid_'.$armKey;
                }
                $shape = is_array($receipt['command_shape_summary'] ?? null) ? (array) $receipt['command_shape_summary'] : [];
                if (($shape['governed_cursor_cli_shape'] ?? false) !== true) {
                    $blockers[] = 'meta_provider_receipt_cursor_command_shape_invalid:'.$armKey;
                    $missing[] = 'meta_provider_receipt.'.$armKey.'.command_shape.governed_cursor_cli_shape';
                    $reasons[] = 'meta_provider_receipt_cursor_command_shape_invalid_'.$armKey;
                }
                foreach ([
                    'print_mode',
                    'output_format_stream_json',
                    'model_arg_present',
                    'force_absent',
                    'resume_absent',
                    'prompt_arg_absent',
                ] as $signal) {
                    if (($shape[$signal] ?? false) !== true) {
                        $blockers[] = 'meta_provider_receipt_cursor_command_shape_'.$signal.'_missing:'.$armKey;
                        $missing[] = 'meta_provider_receipt.'.$armKey.'.command_shape.'.$signal;
                        $reasons[] = 'meta_provider_receipt_cursor_command_shape_'.$signal.'_missing_'.$armKey;
                    }
                }
                $streamReport = $this->validateStreamJsonReceipt($armKey, $receipt);
                $blockers = array_merge($blockers, $streamReport['blockers']);
                $missing = array_merge($missing, $streamReport['missing_evidence']);
                $reasons = array_merge($reasons, $streamReport['invalid_reasons']);
            }
        }

        foreach ((array) ($pack['cases'] ?? []) as $index => $case) {
            if (! is_array($case)) {
                continue;
            }
            $caseId = (string) ($case['case_id'] ?? 'case_'.$index);
            $isStressCase = (string) ($case['case_set'] ?? '') === 'meta-provider-stress'
                || in_array('meta_provider_stress', $this->stringList($case['measurement_tags'] ?? []), true)
                || is_array($case['meta_provider_stress'] ?? null);
            if (! $isStressCase) {
                continue;
            }

            $promptHash = (string) ($case['human_prompt_hash'] ?? '');
            if (! preg_match('/^[a-f0-9]{64}$/', $promptHash)) {
                $blockers[] = 'meta_provider_case_human_prompt_hash_missing:'.$caseId;
                $missing[] = 'case.'.$caseId.'.human_prompt_hash';
                $reasons[] = 'meta_provider_case_human_prompt_hash_missing_'.$caseId;
            }
            $context = is_array($case['context_profile'] ?? null) ? (array) $case['context_profile'] : [];
            if (($context['schema_version'] ?? null) !== 'atlas.forge.rivals.context_profile.v1') {
                $blockers[] = 'meta_provider_case_context_profile_missing:'.$caseId;
                $missing[] = 'case.'.$caseId.'.context_profile';
                $reasons[] = 'meta_provider_case_context_profile_missing_'.$caseId;
            }
            if (($context['requires_assumption_log'] ?? false) !== true) {
                $blockers[] = 'meta_provider_case_assumption_log_not_required:'.$caseId;
                $reasons[] = 'meta_provider_case_assumption_log_not_required_'.$caseId;
            }
            $contextComplexity = is_array($context['complexity_profile'] ?? null) ? (array) $context['complexity_profile'] : [];
            if (($contextComplexity['schema_version'] ?? null) !== 'atlas.forge.rivals.case_complexity_profile.v1') {
                $blockers[] = 'meta_provider_case_context_complexity_profile_missing:'.$caseId;
                $missing[] = 'case.'.$caseId.'.context_profile.complexity_profile';
                $reasons[] = 'meta_provider_case_context_complexity_profile_missing_'.$caseId;
            }
            $probe = is_array($case['human_prompt_probe'] ?? null) ? (array) $case['human_prompt_probe'] : [];
            if (($probe['schema_version'] ?? null) !== 'atlas.forge.rivals.human_prompt_probe.v1') {
                $blockers[] = 'meta_provider_case_human_prompt_probe_missing:'.$caseId;
                $missing[] = 'case.'.$caseId.'.human_prompt_probe';
                $reasons[] = 'meta_provider_case_human_prompt_probe_missing_'.$caseId;
            }
            foreach (['facts_observed', 'assumptions', 'reversible_decisions', 'scope_boundaries', 'evidence_plan', 'replay_matrix', 'tradeoffs', 'honest_blockers'] as $section) {
                if (! in_array($section, (array) ($probe['requires_sections'] ?? []), true)) {
                    $blockers[] = 'meta_provider_case_human_prompt_probe_section_missing:'.$caseId.':'.$section;
                    $missing[] = 'case.'.$caseId.'.human_prompt_probe.'.$section;
                    $reasons[] = 'meta_provider_case_human_prompt_probe_section_missing_'.$caseId.'_'.$section;
                }
            }
            $probeComplexity = is_array($probe['complexity_profile'] ?? null) ? (array) $probe['complexity_profile'] : [];
            if (($probeComplexity['schema_version'] ?? null) !== 'atlas.forge.rivals.case_complexity_profile.v1') {
                $blockers[] = 'meta_provider_case_human_prompt_probe_complexity_profile_missing:'.$caseId;
                $missing[] = 'case.'.$caseId.'.human_prompt_probe.complexity_profile';
                $reasons[] = 'meta_provider_case_human_prompt_probe_complexity_profile_missing_'.$caseId;
            }
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'invalid_reasons' => array_values(array_unique($reasons)),
            'missing_evidence' => array_values(array_unique($missing)),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{blockers:list<string>,invalid_reasons:list<string>,missing_evidence:list<string>}
     */
    private function validateStreamJsonReceipt(string $armKey, array $receipt): array
    {
        $blockers = [];
        $missing = [];
        $reasons = [];

        if ((string) ($receipt['output_format'] ?? '') !== 'stream-json') {
            $blockers[] = 'meta_provider_receipt_stream_json_output_format_missing:'.$armKey;
            $missing[] = 'meta_provider_receipt.'.$armKey.'.output_format_stream_json';
            $reasons[] = 'meta_provider_receipt_stream_json_output_format_missing_'.$armKey;
        }

        $summary = is_array($receipt['stream_json_summary'] ?? null) ? (array) $receipt['stream_json_summary'] : [];
        if (($summary['system_init_event'] ?? false) !== true) {
            $blockers[] = 'meta_provider_receipt_stream_json_system_init_missing:'.$armKey;
            $missing[] = 'meta_provider_receipt.'.$armKey.'.stream_json.system_init_event';
            $reasons[] = 'meta_provider_receipt_stream_json_system_init_missing_'.$armKey;
        }
        foreach ([
            'system_init_api_key_source_present',
            'system_init_cwd_absolute',
            'system_init_model_present',
            'system_init_permission_mode_present',
            'user_message_event',
        ] as $signal) {
            if (($summary[$signal] ?? false) !== true) {
                $blockers[] = 'meta_provider_receipt_stream_json_'.$signal.'_missing:'.$armKey;
                $missing[] = 'meta_provider_receipt.'.$armKey.'.stream_json.'.$signal;
                $reasons[] = 'meta_provider_receipt_stream_json_'.$signal.'_missing_'.$armKey;
            }
        }
        if (($summary['terminal_result_event'] ?? false) !== true) {
            $blockers[] = 'meta_provider_receipt_stream_json_terminal_result_missing:'.$armKey;
            $missing[] = 'meta_provider_receipt.'.$armKey.'.stream_json.terminal_result_event';
            $reasons[] = 'meta_provider_receipt_stream_json_terminal_result_missing_'.$armKey;
        }
        if (($summary['terminal_result_success'] ?? false) !== true) {
            $blockers[] = 'meta_provider_receipt_stream_json_terminal_result_success_missing:'.$armKey;
            $missing[] = 'meta_provider_receipt.'.$armKey.'.stream_json.terminal_result_success';
            $reasons[] = 'meta_provider_receipt_stream_json_terminal_result_success_missing_'.$armKey;
        }
        if ((int) ($summary['tool_event_count'] ?? 0) <= 0 && ($summary['tool_event_observed'] ?? false) !== true) {
            $blockers[] = 'meta_provider_receipt_stream_json_tool_event_missing:'.$armKey;
            $missing[] = 'meta_provider_receipt.'.$armKey.'.stream_json.tool_event_observed';
            $reasons[] = 'meta_provider_receipt_stream_json_tool_event_missing_'.$armKey;
        }
        if (($summary['session_id_consistent'] ?? false) !== true) {
            $blockers[] = 'meta_provider_receipt_stream_json_session_inconsistent:'.$armKey;
            $missing[] = 'meta_provider_receipt.'.$armKey.'.stream_json.session_id_consistent';
            $reasons[] = 'meta_provider_receipt_stream_json_session_inconsistent_'.$armKey;
        }
        if ($this->stringList($summary['parse_errors'] ?? []) !== []) {
            $blockers[] = 'meta_provider_receipt_stream_json_parse_errors:'.$armKey;
            $reasons[] = 'meta_provider_receipt_stream_json_parse_errors_'.$armKey;
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'invalid_reasons' => array_values(array_unique($reasons)),
            'missing_evidence' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Build the canonical response envelope. Always `claim_ready=false`. Status
     * is `passed` only when no blockers; otherwise `invalid_missing_evidence`
     * when there is a missing artifact, `invalid_hash_mismatch` when there is
     * a hash mismatch, or `blocked` otherwise.
     *
     * @param  list<string>  $blockers
     * @param  list<string>  $invalidReasons
     * @param  list<string>  $missingEvidence
     * @param  array<string,mixed>|null  $context
     * @return array<string,mixed>
     */
    private function emit(string $mode, ?string $runId, array $blockers, array $invalidReasons, array $missingEvidence, ?array $context): array
    {
        $hasHashMismatch = false;
        foreach ($blockers as $b) {
            if (str_starts_with($b, 'artifact_hash_mismatch')) {
                $hasHashMismatch = true;

                break;
            }
        }
        $status = 'passed';
        if ($blockers !== []) {
            if ($missingEvidence !== []) {
                $status = 'invalid_missing_evidence';
            } elseif ($hasHashMismatch) {
                $status = 'invalid_hash_mismatch';
            } else {
                $status = 'blocked';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verified_at' => now()->toJSON(),
            'mode' => $mode,
            'run_id' => $runId,
            'status' => $status,
            'verification_status' => $status,
            'blockers' => $blockers,
            'invalid_reasons' => $invalidReasons,
            'missing_evidence' => $missingEvidence,
            'claim_ready' => false,
            'replay' => $context['replay'] ?? null,
            'evidence_stage' => $context['evidence_stage'] ?? null,
            'pack_schema_version' => is_array($context['pack'] ?? null) ? ($context['pack']['schema_version'] ?? null) : null,
            'artifact_index_present' => isset($context['artifact_index']) && is_array($context['artifact_index']) && $context['artifact_index'] !== [],
            'external_provider_call' => false,
            'no_provider_call' => true,
            'verifier_blocks_fake_evidence' => true,
            'external_rivals_certification_status' => 'blocked',
            'separated_from_external_rivals_certification' => true,
            'next_command' => $status === 'passed'
                ? 'php artisan atlas:forge:rivals replay --run-id='.$runId.' --json'
                : 'fix the listed missing/mismatch entries before claiming any score',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        return JsonFileStore::readArray($path) ?? [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return AiStringListNormalizer::castItemsToStrings($value);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

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
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';

        return match ($mode) {
            self::MODE_DRY_RUN, 'dryrun', 'plan_only' => self::MODE_DRY_RUN,
            self::MODE_FAKE_RUN, 'local_fake', 'fake' => self::MODE_FAKE_RUN,
            self::MODE_REAL_RUN, 'real', 'fair', 'full_power' => self::MODE_REAL_RUN,
            self::MODE_REPLAY, 'integrity', 'check' => self::MODE_REPLAY,
            default => self::MODE_REPLAY,
        };
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
}

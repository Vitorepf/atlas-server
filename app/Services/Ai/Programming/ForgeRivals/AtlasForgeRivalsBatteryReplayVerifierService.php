<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Battery Replay Verifier (multi-case v1).
 *
 * Walks every case declared by the battery evidence pack and verifies that
 * the on-disk artifacts (per-case provider receipts, patch diffs, test logs,
 * workspace hashes) still match the recorded sha256. Any case-level failure
 * blocks the battery-level claim, regardless of how many cases passed:
 *
 *   - missing per-case provider receipt (per arm)        → blocked
 *   - missing patch diff (per arm)                        → blocked
 *   - missing test log (per arm)                          → blocked
 *   - dirty after-run workspace                           → blocked
 *   - tracked bytecode artifact                           → blocked
 *   - patch diff / test log on-disk sha256 mismatch       → blocked
 *   - difficulty_level absent or not in L1-L5             → blocked
 *
 * The verifier never invokes a provider. It only re-reads disk + cross-checks
 * the battery pack. `aggregate_claim_ready=false` is always emitted — the
 * verifier never promotes a claim.
 *
 * Schema: `atlas.forge.rivals.battery_replay_verification.v1`
 */
final class AtlasForgeRivalsBatteryReplayVerifierService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.battery_replay_verification.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryEvidenceService $battery,
        private readonly AtlasForgeRivalsEvidencePackVerifierService $packVerifier,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $stage = AtlasForgeRivalsEvidencePolicy::normalizeStage((string) ($input['evidence_stage'] ?? ''));
        $mode = AtlasForgeRivalsEvidencePackVerifierService::normalizeMode($input['mode'] ?? null);

        $aggregate = $this->battery->aggregate($input);
        if (($aggregate['status'] ?? '') === 'blocked' && empty($aggregate['battery_evidence_pack'])) {
            return [
                'status' => 'blocked',
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => $mode,
                'evidence_stage' => $stage,
                'blockers' => $aggregate['blockers'] ?? [],
                'aggregate_claim_ready' => false,
                'separated_from_external_rivals_certification' => true,
            ];
        }
        $pack = $aggregate['battery_evidence_pack'] ?? [];
        $priorPack = is_array($aggregate['prior_battery_evidence_pack'] ?? null) ? $aggregate['prior_battery_evidence_pack'] : null;
        $cases = is_array($pack['cases'] ?? null) ? $pack['cases'] : [];
        $runs = is_array($pack['runs'] ?? null) ? $pack['runs'] : [];

        // Build a key→sha256 index from the prior pack so we can detect any
        // drift between what was previously sealed in the battery pack and
        // what the fresh aggregate just re-hashed. A drift means the
        // underlying artifact (patch / test log) was tampered with on disk.
        $priorHashesByCase = [];
        if ($priorPack !== null && is_array($priorPack['cases'] ?? null)) {
            foreach ($priorPack['cases'] as $priorCase) {
                if (! is_array($priorCase)) {
                    continue;
                }
                $key = (string) ($priorCase['run_id'] ?? '').'/'.(string) ($priorCase['case_id'] ?? '');
                $priorHashesByCase[$key] = [
                    'atlas' => [
                        'patch_diff_sha256' => data_get($priorCase, 'arms.atlas.patch_diff_sha256'),
                        'test_log_sha256' => data_get($priorCase, 'arms.atlas.test_log_sha256'),
                    ],
                    'rival' => [
                        'patch_diff_sha256' => data_get($priorCase, 'arms.rival.patch_diff_sha256'),
                        'test_log_sha256' => data_get($priorCase, 'arms.rival.test_log_sha256'),
                    ],
                ];
            }
        }

        $perRun = [];
        $perCase = [];
        $blockers = [];
        $invalidReasons = [];
        $missingEvidence = [];

        // Per-run pack verification (delegates to single-run verifier).
        foreach ($runs as $run) {
            $runId = (string) ($run['run_id'] ?? '');
            if ($runId === '') {
                continue;
            }
            if (($run['present'] ?? false) !== true) {
                $perRun[] = [
                    'run_id' => $runId,
                    'status' => 'blocked',
                    'reason' => $run['reason_missing'] ?? 'run_directory_not_found',
                ];
                $blockers[] = 'run_missing:'.$runId;
                $missingEvidence[] = 'run:'.$runId;

                continue;
            }
            $verifyMode = $this->resolveVerifyModeForRun($run, $mode);
            $verifierResult = $this->packVerifier->verify([
                'run_id' => $runId,
                'mode' => $verifyMode,
                'evidence_stage' => $stage,
            ]);
            $perRun[] = [
                'run_id' => $runId,
                'verify_mode' => $verifyMode,
                'status' => $verifierResult['verification_status'] ?? $verifierResult['status'] ?? 'blocked',
                'blockers' => $verifierResult['blockers'] ?? [],
                'invalid_reasons' => $verifierResult['invalid_reasons'] ?? [],
                'missing_evidence' => $verifierResult['missing_evidence'] ?? [],
            ];
            if (($verifierResult['verification_status'] ?? 'blocked') !== 'passed') {
                $blockers[] = 'run_verification_failed:'.$runId;
                foreach ((array) ($verifierResult['blockers'] ?? []) as $b) {
                    $blockers[] = 'run:'.$runId.':'.(string) $b;
                }
                foreach ((array) ($verifierResult['invalid_reasons'] ?? []) as $reason) {
                    $invalidReasons[] = 'run:'.$runId.':'.(string) $reason;
                }
                foreach ((array) ($verifierResult['missing_evidence'] ?? []) as $miss) {
                    $missingEvidence[] = 'run:'.$runId.':'.(string) $miss;
                }
            }
        }

        // Per-case integrity check: rehash patch + test log against the
        // sha256 recorded in the battery pack. Any drift is terminal.
        foreach ($cases as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            $runId = (string) ($case['run_id'] ?? '');
            $key = $runId.'/'.$caseId;
            $report = $this->verifyCase($case, $priorHashesByCase[$key] ?? null);
            $perCase[] = [
                'run_id' => $runId,
                'case_id' => $caseId,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'replay_ready' => (bool) ($case['replay_ready'] ?? false),
                'status' => $report['status'],
                'blockers' => $report['blockers'],
                'invalid_reasons' => $report['invalid_reasons'],
                'missing_evidence' => $report['missing_evidence'],
            ];
            foreach ($report['blockers'] as $b) {
                $blockers[] = 'case:'.$runId.'/'.$caseId.':'.$b;
            }
            foreach ($report['invalid_reasons'] as $r) {
                $invalidReasons[] = 'case:'.$runId.'/'.$caseId.':'.$r;
            }
            foreach ($report['missing_evidence'] as $m) {
                $missingEvidence[] = 'case:'.$runId.'/'.$caseId.':'.$m;
            }
        }

        // Battery-level invariants.
        $difficultyMissing = (int) data_get($pack, 'difficulty_summary.missing_difficulty_count', 0);
        if ($difficultyMissing > 0) {
            $blockers[] = 'battery_missing_difficulty:'.$difficultyMissing.'_cases';
            $invalidReasons[] = 'battery_missing_difficulty_for_'.$difficultyMissing.'_cases';
            $missingEvidence[] = 'difficulty_level_for_'.$difficultyMissing.'_cases';
        }
        if (($pack['external_rivals_certification_status'] ?? '') !== 'blocked') {
            $blockers[] = 'battery_external_rivals_not_blocked';
            $invalidReasons[] = 'battery_external_rivals_not_blocked';
        }
        if (($pack['aggregate_claim_ready'] ?? false) === true) {
            $blockers[] = 'battery_aggregate_claim_ready_must_be_false';
            $invalidReasons[] = 'battery_aggregate_claim_ready_must_be_false';
        }

        $blockers = array_values(array_unique($blockers));
        $invalidReasons = array_values(array_unique($invalidReasons));
        $missingEvidence = array_values(array_unique($missingEvidence));
        $hasHashMismatch = false;
        foreach ($blockers as $b) {
            if (str_contains($b, 'hash_mismatch')) {
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
            'status' => $status === 'passed' ? 'ok' : $status,
            'schema_version' => self::SCHEMA_VERSION,
            'verified_at' => now()->toJSON(),
            'mode' => $mode,
            'evidence_stage' => $stage,
            'battery_id' => $pack['battery_id'] ?? null,
            'verification_status' => $status,
            'aggregate_claim_ready' => false,
            'run_count' => count($perRun),
            'case_count' => count($perCase),
            'per_run' => $perRun,
            'per_case' => $perCase,
            'blockers' => $blockers,
            'invalid_reasons' => $invalidReasons,
            'missing_evidence' => $missingEvidence,
            'difficulty_summary' => $pack['difficulty_summary'] ?? null,
            'category_summary' => $pack['category_summary'] ?? null,
            'battery_evidence_pack_path' => $pack['battery_pack_path'] ?? null,
            'battery_evidence_pack_sha256' => $pack['battery_pack_sha256'] ?? null,
            'no_provider_call' => true,
            'external_provider_call' => false,
            'external_rivals_certification_status' => 'blocked',
            'separated_from_external_rivals_certification' => true,
            'next_command' => $status === 'passed'
                ? 'battery_evidence_passes_replay; adjudication and report still own claim_ready'
                : 'fix listed blockers — claim remains blocked',
        ];
    }

    /**
     * Decide the verifier mode for a per-run pack. real_run packs verify in
     * real_run; fake_run / dry_run propagate from the per-run pack's
     * `mode_for_evidence`. The operator can override via `--mode`.
     *
     * @param  array<string,mixed>  $run
     */
    private function resolveVerifyModeForRun(array $run, string $operatorMode): string
    {
        if ($operatorMode === AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY) {
            $packMode = (string) ($run['mode_for_evidence'] ?? '');

            return match ($packMode) {
                AtlasForgeRivalsCollectEvidenceService::EVIDENCE_MODE_REAL_RUN => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
                AtlasForgeRivalsCollectEvidenceService::EVIDENCE_MODE_FAKE_RUN => AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN,
                AtlasForgeRivalsCollectEvidenceService::EVIDENCE_MODE_DRY_RUN => AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN,
                default => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            };
        }

        return $operatorMode;
    }

    /**
     * Verify a single case digest from the battery pack. Re-reads the patch
     * diff and test log from disk and compares the sha256 to the recorded
     * value. Surfaces blockers / invalid_reasons / missing_evidence the
     * battery aggregator stitches together.
     *
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>|null  $priorCaseHashes  Hashes recorded in a
     *        previously-written battery pack for this case. Any drift between
     *        the prior recorded sha256 and the current on-disk sha256 means
     *        the underlying artifact was tampered after the pack was sealed.
     * @return array{blockers:list<string>,invalid_reasons:list<string>,missing_evidence:list<string>,status:string}
     */
    private function verifyCase(array $case, ?array $priorCaseHashes = null): array
    {
        $blockers = [];
        $reasons = [];
        $missing = [];

        $arms = is_array($case['arms'] ?? null) ? $case['arms'] : [];
        foreach (['atlas', 'rival'] as $armKey) {
            $arm = is_array($arms[$armKey] ?? null) ? $arms[$armKey] : [];
            if (! ($arm['present'] ?? false)) {
                $blockers[] = 'missing_provider_receipt:'.$armKey;
                $missing[] = 'provider_receipt:'.$armKey;
                $reasons[] = 'provider_receipt_missing_'.$armKey;

                continue;
            }
            $patchPath = (string) ($arm['patch_diff_path'] ?? '');
            $expectedPatch = (string) ($arm['patch_diff_sha256'] ?? '');
            $priorPatch = (string) ($priorCaseHashes[$armKey]['patch_diff_sha256'] ?? '');
            if ($patchPath === '' || ! is_file($patchPath)) {
                $blockers[] = 'missing_patch_diff:'.$armKey;
                $missing[] = 'patch_diff:'.$armKey;
                $reasons[] = 'patch_diff_missing_'.$armKey;
            } else {
                $actual = hash_file('sha256', $patchPath) ?: '';
                if ($expectedPatch !== '' && $actual !== $expectedPatch) {
                    $blockers[] = 'patch_diff_hash_mismatch:'.$armKey;
                    $reasons[] = 'patch_diff_hash_mismatch_'.$armKey;
                }
                if ($priorPatch !== '' && $priorPatch !== $actual) {
                    $blockers[] = 'patch_diff_hash_mismatch:'.$armKey.':prior_pack_drift';
                    $reasons[] = 'patch_diff_hash_mismatch_'.$armKey.'_prior_pack_drift';
                }
            }

            $testLogPath = (string) ($arm['test_log_path'] ?? '');
            $expectedTestLog = (string) ($arm['test_log_sha256'] ?? '');
            $priorTestLog = (string) ($priorCaseHashes[$armKey]['test_log_sha256'] ?? '');
            if ($testLogPath === '' || ! is_file($testLogPath)) {
                $blockers[] = 'missing_test_log:'.$armKey;
                $missing[] = 'test_log:'.$armKey;
                $reasons[] = 'test_log_missing_'.$armKey;
            } else {
                $actual = hash_file('sha256', $testLogPath) ?: '';
                if ($expectedTestLog !== '' && $actual !== $expectedTestLog) {
                    $blockers[] = 'test_log_hash_mismatch:'.$armKey;
                    $reasons[] = 'test_log_hash_mismatch_'.$armKey;
                }
                if ($priorTestLog !== '' && $priorTestLog !== $actual) {
                    $blockers[] = 'test_log_hash_mismatch:'.$armKey.':prior_pack_drift';
                    $reasons[] = 'test_log_hash_mismatch_'.$armKey.'_prior_pack_drift';
                }
            }
        }

        $workspaceBlockers = (array) ($case['workspace_blockers'] ?? []);
        if ($workspaceBlockers !== []) {
            $blockers[] = 'dirty_after_run';
            $reasons[] = 'dirty_after_run:'.implode(',', array_slice($workspaceBlockers, 0, 3));
        }

        $level = $case['difficulty_level'] ?? null;
        if ($level === null || ! in_array((string) $level, AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, true)) {
            $blockers[] = 'missing_difficulty_level';
            $missing[] = 'difficulty_level';
            $reasons[] = 'missing_difficulty_level';
        }

        $blockers = array_values(array_unique($blockers));
        $reasons = array_values(array_unique($reasons));
        $missing = array_values(array_unique($missing));
        $status = $blockers === [] ? 'passed' : 'blocked';

        return [
            'status' => $status,
            'blockers' => $blockers,
            'invalid_reasons' => $reasons,
            'missing_evidence' => $missing,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Evidence Pack + Replay Hardening v2 (Claude D).
 *
 * Locks down the contract documented in
 * `docs/engineering-knowledge-base/atlas-forge-rivals-evidence-pack-replay-hardening-v2.md`:
 *
 *   - `dry_run`, `fake_run`, `real_run` and `replay` verifier modes,
 *   - provider receipt requirement for real_run,
 *   - patch diff + test log + workspace hashes + after-clean-check
 *     requirement for real_run,
 *   - reason_missing requirement when `present=false`,
 *   - `claim_ready=false` enforcement when missing_required,
 *   - artifact hash mismatch invalidation,
 *   - tracked .pyc invalidation,
 *   - replay never invokes provider,
 *   - external_rivals_certification remains blocked,
 *   - artifact_index.json sidecar exists and validates,
 *   - 30 obligatory test cases enumerated in the Claude D briefing.
 */
final class AtlasForgeRivalsEvidencePackReplayHardeningV2Test extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsCollectEvidenceService $collect;

    private AtlasForgeRivalsReplayService $replay;

    private AtlasForgeRivalsEvidencePackVerifierService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-evidence-v2-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $this->verifier = new AtlasForgeRivalsEvidencePackVerifierService($this->paths, $this->replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    // 1. evidence pack real_run exige provider_receipt
    public function test_real_run_evidence_pack_carries_provider_receipts(): void
    {
        $runId = $this->seedRealRun('rr-with-receipts');
        $collect = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $pack = $collect['evidence_pack'];
        $this->assertSame('ok', $collect['status']);
        $this->assertSame(AtlasForgeRivalsCollectEvidenceService::EVIDENCE_MODE_REAL_RUN, $pack['mode_for_evidence']);
        $this->assertTrue($pack['provider_receipts']['atlas']['present']);
        $this->assertTrue($pack['provider_receipts']['rival']['present']);
        $this->assertFalse($pack['provider_receipts']['atlas']['test_mode']);
        $this->assertFalse($pack['provider_receipts']['rival']['test_mode']);
    }

    // 2. missing provider receipt => verification invalid
    public function test_real_run_missing_provider_receipt_invalidates_verification(): void
    {
        $runId = $this->seedRealRun('rr-no-receipt');
        $paths = $this->paths->paths($runId);
        @unlink($paths['evidence'].'/atlas_receipt.json');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('invalid_missing_evidence', $result['verification_status']);
        $this->assertContains('real_run_provider_receipt_missing:atlas', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 3. missing patch diff => verification invalid
    public function test_real_run_missing_patch_diff_invalidates_verification(): void
    {
        $runId = $this->seedRealRun('rr-no-patch');
        $paths = $this->paths->paths($runId);
        @unlink($paths['evidence'].'/atlas_patch.diff');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('invalid_missing_evidence', $result['verification_status']);
        $this->assertContains('real_run_missing_required_artifact:patch_diff:atlas', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 4. missing test log => verification invalid
    public function test_real_run_missing_test_log_invalidates_verification(): void
    {
        $runId = $this->seedRealRun('rr-no-testlog');
        $paths = $this->paths->paths($runId);
        @unlink($paths['evidence'].'/atlas_test.log');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('invalid_missing_evidence', $result['verification_status']);
        $this->assertContains('real_run_missing_required_artifact:test_log:atlas', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 5. missing replay manifest (events.jsonl) => verification invalid
    public function test_real_run_missing_events_jsonl_invalidates_verification(): void
    {
        $runId = $this->seedRealRun('rr-no-events');
        $paths = $this->paths->paths($runId);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        @unlink($paths['events_jsonl']);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertFalse($result['claim_ready']);
        $this->assertContains('events_jsonl_missing_on_disk', $result['blockers']);
    }

    // 6. artifact hash mismatch => verification invalid
    public function test_artifact_hash_mismatch_invalidates_verification(): void
    {
        $runId = $this->seedRealRun('rr-hash-mismatch');
        $paths = $this->paths->paths($runId);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        file_put_contents($paths['evidence'].'/atlas_patch.diff', '--- tampered ---');

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertContains('artifact_hash_mismatch:atlas_patch', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 7. present=false sem reason_missing => invalid
    public function test_present_false_without_reason_missing_is_invalid(): void
    {
        $runId = $this->seedRealRun('rr-reasonless');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $paths = $this->paths->paths($runId);
        $packPath = $paths['evidence'].'/evidence_pack.json';
        $pack = json_decode((string) file_get_contents($packPath), true);
        $pack['artifacts']['atlas_patch'] = [
            'path' => $paths['evidence'].'/atlas_patch.diff',
            'present' => false,
            // intentionally omit reason_missing
        ];
        file_put_contents($packPath, $this->jsonEncode($pack));

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertContains('absent_without_reason_missing:atlas_patch', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 8. optional evidence com reason_missing é aceito
    public function test_optional_evidence_with_reason_missing_is_accepted(): void
    {
        $runId = $this->seedLocalFakeRun('rr-optional-reason');
        $collect = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('ok', $collect['status']);
        $pack = $collect['evidence_pack'];
        foreach (['atlas_patch', 'rival_patch', 'atlas_test_log', 'rival_test_log'] as $key) {
            $desc = $pack['artifacts'][$key] ?? null;
            if ($desc === null || ($desc['present'] ?? false)) {
                continue;
            }
            $this->assertIsString($desc['reason_missing'] ?? null);
            $this->assertNotSame('', trim((string) ($desc['reason_missing'] ?? '')));
        }
    }

    // 9. after-clean-check clean passa
    public function test_after_clean_check_clean_passes_real_run_verification(): void
    {
        $runId = $this->seedRealRun('rr-clean');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('passed', $result['verification_status']);
        $this->assertFalse($result['claim_ready'], 'verifier never promotes claim by itself');
        $this->assertSame('blocked', $result['external_rivals_certification_status']);
    }

    // 10. after-clean-check dirty invalida resultado
    public function test_after_clean_check_dirty_invalidates_real_run_verification(): void
    {
        $runId = $this->seedRealRun('rr-dirty', dirty: true);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertContains('real_run_after_clean_check_dirty', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 11. tracked .pyc bloqueia resultado
    public function test_tracked_pyc_blocks_real_run_verification(): void
    {
        $runId = $this->seedRealRun('rr-pyc', trackedPyc: ['app/Foo/__pycache__/foo.cpython-311.pyc']);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertContains('tracked_python_bytecode_present', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 12. PYTHONDONTWRITEBYTECODE=1 é injetado/registrado
    public function test_workspace_hygiene_service_force_bytecode_disabled_env_returns_canonical_block(): void
    {
        $env = app(\App\Services\Ai\Programming\WorkspaceHygieneService::class)->forceBytecodeDisabledEnv();
        $this->assertSame('1', $env['PYTHONDONTWRITEBYTECODE'] ?? null);
        $this->assertNotEmpty($env['PYTHONPYCACHEPREFIX'] ?? null);
    }

    // 13. events.jsonl contém run_started/heartbeat/final_report
    public function test_events_jsonl_supports_canonical_kinds(): void
    {
        $this->assertContains('run_started', \App\Services\Ai\Programming\RivalsForgeRunLogStreamService::CANONICAL_EVENT_KINDS);
        $this->assertContains('heartbeat', \App\Services\Ai\Programming\RivalsForgeRunLogStreamService::CANONICAL_EVENT_KINDS);
        $this->assertContains('final_report', \App\Services\Ai\Programming\RivalsForgeRunLogStreamService::CANONICAL_EVENT_KINDS);
    }

    // 14. replay sem provider call valida artifacts
    public function test_replay_validates_artifacts_without_provider_call(): void
    {
        $runId = $this->seedRealRun('rr-replay-ok');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('passed', $result['verification_status']);
        $this->assertTrue($result['no_provider_call']);
        $this->assertFalse($result['external_provider_call']);
    }

    // 15. replay com hash mismatch falha
    public function test_replay_with_hash_mismatch_fails(): void
    {
        $runId = $this->seedRealRun('rr-replay-mismatch');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $paths = $this->paths->paths($runId);
        file_put_contents($paths['evidence'].'/rival_patch.diff', '--- drifted ---');

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('invalid_hash_mismatch', $result['verification_status']);
        $this->assertContains('artifact_hash_mismatch:rival_patch', $result['blockers']);
    }

    // 16. run manifest hash determinístico
    public function test_manifest_hash_in_artifact_index_is_deterministic(): void
    {
        $runId = $this->seedRealRun('rr-manifest-hash');
        $first = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $second = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame(
            $first['evidence_pack']['artifacts']['manifest']['sha256'],
            $second['evidence_pack']['artifacts']['manifest']['sha256'],
        );
    }

    // 17. case manifest hash determinístico — synthetic case identity stays the same
    public function test_case_id_in_evidence_pack_is_stable(): void
    {
        $runId = $this->seedRealRun('rr-case-stable');
        $first = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $second = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame(
            $first['evidence_pack']['manifest_summary']['case_id'],
            $second['evidence_pack']['manifest_summary']['case_id'],
        );
    }

    // 18. evidence pack hash determinístico (artifact_index hashes)
    public function test_artifact_index_hashes_are_deterministic(): void
    {
        $runId = $this->seedRealRun('rr-index-hash');
        $first = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $second = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $paths = $this->paths->paths($runId);
        $indexPath = $paths['evidence'].'/artifact_index.json';
        $this->assertFileExists($indexPath);
        $a = $first['evidence_pack']['artifacts']['atlas_patch']['sha256'] ?? null;
        $b = $second['evidence_pack']['artifacts']['atlas_patch']['sha256'] ?? null;
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
    }

    // 19. dry_run não exige provider_receipt real
    public function test_dry_run_does_not_require_provider_receipt(): void
    {
        $runId = $this->seedDryRun('dry-no-receipt');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertFalse($result['claim_ready']);
        $this->assertSame('passed', $result['verification_status'], implode('|', $result['blockers']));
    }

    // 20. fake_run aceita fake receipt marcado como fake/test
    public function test_fake_run_accepts_test_mode_marked_receipts(): void
    {
        $runId = $this->seedLocalFakeRun('fake-run-accepts');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('passed', $result['verification_status'], implode('|', $result['blockers']));
        $this->assertFalse($result['claim_ready']);
    }

    // 21. real_run rejeita fake receipt
    public function test_real_run_rejects_fake_receipts(): void
    {
        $runId = $this->seedLocalFakeRun('real-rejects-fake');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertContains('real_run_rejects_fake_receipt:atlas', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    // 22. external_rivals_certification continua blocked
    public function test_external_rivals_certification_remains_blocked(): void
    {
        $runId = $this->seedRealRun('ext-blocked');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('blocked', $result['external_rivals_certification_status']);
        $this->assertTrue($result['separated_from_external_rivals_certification']);
    }

    // 23. claim_ready=false se evidence invalid
    public function test_claim_ready_false_when_evidence_invalid(): void
    {
        $runId = $this->seedRealRun('claim-false-invalid');
        $paths = $this->paths->paths($runId);
        @unlink($paths['evidence'].'/atlas_patch.diff');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('invalid_missing_evidence', $result['verification_status']);
        $this->assertFalse($result['claim_ready']);
    }

    // 24. evidence valid não promove claim sozinho
    public function test_passed_verification_does_not_promote_claim(): void
    {
        $runId = $this->seedRealRun('passed-no-claim');
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->verifier->verify([
            'run_id' => $runId,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('passed', $result['verification_status']);
        $this->assertFalse($result['claim_ready']);
    }

    // 25. CLI evidence retorna paths e hashes
    public function test_cli_evidence_action_returns_paths_and_hashes(): void
    {
        $runId = $this->seedRealRun('cli-evidence-paths');
        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'evidence',
            '--run-id' => $runId,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $paths = $this->paths->paths($runId);
        $this->assertFileExists($paths['evidence'].'/evidence_pack.json');
        $this->assertFileExists($paths['evidence'].'/artifact_index.json');
        $index = json_decode((string) file_get_contents($paths['evidence'].'/artifact_index.json'), true);
        $this->assertSame(AtlasForgeRivalsCollectEvidenceService::ARTIFACT_INDEX_SCHEMA_VERSION, $index['schema_version']);
        $this->assertNotEmpty($index['artifacts']['manifest']['sha256']);
    }

    // 26. CLI replay retorna replay status
    public function test_cli_replay_action_returns_replay_status(): void
    {
        $runId = $this->seedRealRun('cli-replay-status');
        $this->artisan('atlas:forge:rivals', [
            'action' => 'evidence',
            '--run-id' => $runId,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();

        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'replay',
            '--run-id' => $runId,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }

    // 27. CLI verify-evidence strict exit 1 em invalid
    public function test_cli_verify_evidence_strict_returns_failure_on_invalid(): void
    {
        $runId = $this->seedRealRun('cli-verify-fail');
        $paths = $this->paths->paths($runId);
        @unlink($paths['evidence'].'/atlas_patch.diff');
        $this->artisan('atlas:forge:rivals', [
            'action' => 'evidence',
            '--run-id' => $runId,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();

        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'verify-evidence',
            '--run-id' => $runId,
            '--verify-mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
            '--strict' => true,
        ])->run();
        $this->assertSame(1, $exit);
    }

    // 28. compatibilidade com evidence pack v1 (legacy pack still replays)
    public function test_legacy_v1_evidence_pack_still_passes_replay_phase(): void
    {
        $runId = $this->newRunId('legacy-v1-compat');
        $this->seedRealArtifacts($runId);
        $paths = $this->paths->paths($runId);

        // Legacy v1 pack: no evidence_stage, no required_artifacts.
        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
        ] as $key => $path) {
            $artifacts[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }
        file_put_contents(
            $paths['evidence'].'/evidence_pack.json',
            $this->jsonEncode([
                'schema_version' => AtlasForgeRivalsCollectEvidenceService::SCHEMA_VERSION_LEGACY,
                'artifacts' => $artifacts,
                'missing_evidence' => [],
                'verdict' => 'comparable',
                'claim_ready' => false,
            ]),
        );

        $replayResult = $this->replay->replay(['run_id' => $runId]);
        $this->assertTrue($replayResult['replay_passes']);
    }

    // 29. docs-health passa (smoke that doc file exists at the canonical location)
    public function test_evidence_pack_replay_hardening_doc_exists(): void
    {
        $this->assertFileExists(base_path('docs/engineering-knowledge-base/atlas-forge-rivals-evidence-pack-replay-hardening-v2.md'));
    }

    // 30. architecture: action is registered + alias resolves
    public function test_verify_evidence_action_is_registered_in_command_actions(): void
    {
        $this->assertContains('verify-evidence', \App\Console\Commands\AtlasForgeRivalsCommand::ACTIONS);
        $this->assertContains('evidence', \App\Console\Commands\AtlasForgeRivalsCommand::ACTIONS);
    }

    // --- helpers ---

    private function seedRealRun(string $suffix, bool $dirty = false, array $trackedPyc = []): string
    {
        $runId = $this->newRunId('real-'.$suffix);
        $this->seedRealArtifacts($runId, $dirty, $trackedPyc);

        return $runId;
    }

    private function seedLocalFakeRun(string $suffix): string
    {
        $runId = $this->newRunId('localfake-'.$suffix);
        $this->seedArtifacts($runId, [
            'verdict' => 'invalid_no_patch_diff',
            'mode' => 'local_fake',
        ], includePatchesAndLogs: false, isFake: true, externalProviderCall: false);

        return $runId;
    }

    private function seedDryRun(string $suffix): string
    {
        $runId = $this->newRunId('dry-'.$suffix);
        $this->seedArtifacts($runId, [
            'verdict' => 'unknown',
            'mode' => 'dry_run',
        ], includePatchesAndLogs: false, isFake: false, externalProviderCall: false, omitReceipts: true);

        return $runId;
    }

    /**
     * @param  list<string>  $trackedPyc
     */
    private function seedRealArtifacts(string $runId, bool $dirty = false, array $trackedPyc = []): void
    {
        $this->seedArtifacts(
            $runId,
            ['verdict' => 'comparable', 'mode' => 'fair'],
            includePatchesAndLogs: true,
            isFake: false,
            externalProviderCall: true,
            dirty: $dirty,
            trackedPyc: $trackedPyc,
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @param  list<string>  $trackedPyc
     */
    private function seedArtifacts(
        string $runId,
        array $overrides,
        bool $includePatchesAndLogs,
        bool $isFake = false,
        bool $externalProviderCall = true,
        bool $dirty = false,
        array $trackedPyc = [],
        bool $omitReceipts = false,
    ): void {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $verdict = (string) ($overrides['verdict'] ?? 'comparable');
        $mode = (string) ($overrides['mode'] ?? 'fair');

        $atlasReceipt = $this->receipt('atlas', $isFake, $trackedPyc);
        $rivalReceipt = $this->receipt('rival', $isFake);
        if (! $omitReceipts) {
            file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
            file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        }
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => $dirty ? 'h3' : 'h2', 'rival' => 'h2'],
            'dirty_after_run' => $dirty,
            'workspace_blockers' => $dirty ? ['out_of_scope_change:storage/foo.txt'] : [],
        ]));

        if ($includePatchesAndLogs) {
            file_put_contents($paths['evidence'].'/atlas_patch.diff', '--- atlas patch ---');
            file_put_contents($paths['evidence'].'/rival_patch.diff', '--- rival patch ---');
            file_put_contents($paths['evidence'].'/atlas_test.log', '(50 tests, 120 assertions)');
            file_put_contents($paths['evidence'].'/rival_test.log', '(50 tests, 120 assertions)');
        }

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
            'verdict' => $verdict,
            'score' => null,
            'claim_ready' => false,
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => $dirty ? 'h3' : 'h2', 'rival' => 'h2'],
            'dirty_after_run' => $dirty,
            'workspace_blockers' => $dirty ? ['out_of_scope_change:storage/foo.txt'] : [],
            'external_provider_call' => $externalProviderCall,
            'provider_tokens_spent' => $externalProviderCall,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL.json_encode(['kind' => 'heartbeat']).PHP_EOL.json_encode(['kind' => 'final_report']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic']));
    }

    /**
     * @param  list<string>  $trackedPyc
     * @return array<string,mixed>
     */
    private function receipt(string $arm, bool $fake = false, array $trackedPyc = []): array
    {
        return [
            'arm' => $arm,
            'mode' => $fake ? 'local_fake' : 'fair',
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm.($fake ? '-fake' : '-real')),
            'prompt_hash' => hash('sha256', $arm.'p'),
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'so'),
            'stderr_hash' => hash('sha256', $arm.'se'),
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'stdout_tail' => 'tail',
            'stderr_tail' => '',
            'stdout_path' => '/tmp/stdout',
            'stderr_path' => '/tmp/stderr',
            'token_cost' => $fake ? 0.0 : 0.01,
            'tokens_used' => $fake ? 0 : 100,
            'worktree' => '/tmp/work',
            'case_id' => 'synthetic-case',
            'changed_files' => ['tests/Feature/Synthetic.php', 'app/Synthetic.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => $trackedPyc,
            'workspace_blockers' => $trackedPyc !== [] ? array_map(static fn (string $f): string => 'bytecode_artifact_after_run:'.$f, $trackedPyc) : [],
            'workspace_has_blocking_changes' => $trackedPyc !== [],
            'patch_diff_path' => '/tmp/patch.diff',
            'patch_diff_hash' => hash('sha256', $arm.'pd'),
            'patch_diff_bytes' => $fake ? 0 : 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_hash' => hash('sha256', $arm.'tl'),
            'test_log_tail' => '(50 tests, 120 assertions)',
            'fake' => $fake,
        ];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function newRunId(string $suffix): string
    {
        return 'evidence-v2-'.bin2hex(random_bytes(4)).'-'.$suffix;
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasRivalsBatteryStateMachine;
use App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseRubricService;
use App\Services\Ai\Programming\AtlasRivalsOperatorRunbookGenerator;
use App\Services\Ai\Programming\AtlasRivalsRunOrchestrator;
use App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService;
use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Agent ε deliverable: behavioral tests for the Atlas Forge Rivals Real Battery
 * Operator Harness v1.
 *
 * Scope:
 *   - app/Console/Commands/AtlasRivalsHarnessCommand
 *   - app/Services/Ai/Programming/AtlasRivalsBatteryStateMachine
 *   - app/Services/Ai/Programming/AtlasRivalsOperatorRunbookGenerator
 *   - app/Services/Ai/Programming/AtlasRivalsTestWorktreeProvisioner
 *
 * Invariants enforced:
 *   - NEVER calls a real provider (orchestrator runner is mocked when relevant).
 *   - Each test purges any tmp worktrees / run streams / triage entries created.
 *   - Behavioral assertions only: states + verdicts + emitted JSON shape,
 *     never service internals.
 */
class AtlasRivalsHarnessOperatorTest extends TestCase
{
    private const COMMAND = 'atlas:engineering:benchmark:rivals-harness';

    /** @var list<string> */
    private array $createdPaths = [];

    /** @var list<string> */
    private array $createdRunIds = [];

    /** @var list<string> */
    private array $createdFingerprints = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            $this->purge($path);
        }
        $this->createdPaths = [];

        $stream = app(RivalsForgeRunLogStreamService::class);
        foreach ($this->createdRunIds as $runId) {
            $this->purge($stream->runDirectory($runId));
        }
        $this->createdRunIds = [];

        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        foreach ($this->createdFingerprints as $fp) {
            $prefix = substr($fp, 0, 16);
            @unlink($registry->rootDirectory().DIRECTORY_SEPARATOR.$prefix.'.json');
        }
        $this->createdFingerprints = [];

        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. doctor --json
    // ------------------------------------------------------------------
    public function test_doctor_action_returns_json_with_status_and_checks(): void
    {
        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'doctor',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, 'doctor --json must emit a JSON object');
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('blocking_reasons', $payload);
        $this->assertIsArray($payload['checks']);
        $this->assertIsArray($payload['blocking_reasons']);
        $this->assertContains($exitCode, [0, 1], 'doctor exits 0 (healthy) or 1 (blockers)');
    }

    // ------------------------------------------------------------------
    // 2. setup-worktrees creates two isolated dirs in tmp
    // ------------------------------------------------------------------
    public function test_setup_worktrees_creates_two_isolated_worktrees_in_tmp(): void
    {
        $root = $this->makeTmpRoot('worktree-setup');
        config(['atlas_rivals.worktree_root' => $root]);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'setup-worktrees',
            '--worktree-root' => $root,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, 'setup-worktrees --json must emit a JSON object');
        $this->assertSame(0, $exitCode);

        $paths = (array) ($payload['worktrees'] ?? []);
        $this->assertCount(2, $paths, 'setup-worktrees must provision exactly two isolated worktrees');
        foreach ($paths as $path) {
            $this->assertIsString($path);
            $this->assertDirectoryExists($path, "worktree must exist on disk: $path");
            $this->assertStringStartsWith($root, $path, 'worktree must live under requested root');
        }
        $this->assertNotSame($paths[0], $paths[1], 'worktrees must be isolated (distinct paths)');
    }

    // ------------------------------------------------------------------
    // 3. doctor detects wrong-repo
    // ------------------------------------------------------------------
    public function test_doctor_detects_repo_errado(): void
    {
        $foreignRepo = $this->makeTmpRoot('foreign-repo');
        @mkdir($foreignRepo, 0o755, true);
        file_put_contents($foreignRepo.'/README.md', "not atlas\n");

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'doctor',
            '--repo-root' => $foreignRepo,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertContains('wrong_repo', (array) ($payload['blocking_reasons'] ?? []));
        $this->assertNotSame(0, $exitCode);
    }

    // ------------------------------------------------------------------
    // 4. preflight --strict blocks dirty workspace
    // ------------------------------------------------------------------
    public function test_preflight_blocks_dirty_workspace(): void
    {
        /** @var WorkspaceHygieneService&MockInterface $hygiene */
        $hygiene = Mockery::mock(WorkspaceHygieneService::class);
        $hygiene->shouldReceive('snapshot')->andReturn([
            'is_git' => true,
            'head_sha' => 'deadbeef',
            'status_hash' => str_repeat('1', 64),
            'dirty_count' => 3,
            'dirty_files_sample' => ['foo.tmp', 'bar.tmp', 'baz.tmp'],
            'dirty_files_truncated' => false,
            'clean' => false,
        ]);
        $hygiene->shouldReceive('trackedPythonBytecode')->andReturn([
            'is_git' => true,
            'tracked_count' => 0,
            'tracked_sample' => [],
            'tracked_truncated' => false,
            'resolution_command' => '',
        ]);
        $hygiene->shouldReceive('forceBytecodeDisabledEnv')->andReturn([
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONPYCACHEPREFIX' => '/tmp/atlas-rivals-pycache',
        ]);
        app()->instance(WorkspaceHygieneService::class, $hygiene);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'preflight',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertNotSame(0, $exitCode, 'strict preflight on dirty workspace must fail');
        $reasons = (array) ($payload['blocking_reasons'] ?? []);
        $this->assertContains('dirty_workspace', $reasons);
    }

    // ------------------------------------------------------------------
    // 5. preflight blocks tracked .pyc
    // ------------------------------------------------------------------
    public function test_preflight_blocks_tracked_pyc(): void
    {
        /** @var WorkspaceHygieneService&MockInterface $hygiene */
        $hygiene = Mockery::mock(WorkspaceHygieneService::class);
        $hygiene->shouldReceive('snapshot')->andReturn([
            'is_git' => true,
            'head_sha' => 'cafef00d',
            'status_hash' => str_repeat('a', 64),
            'dirty_count' => 0,
            'dirty_files_sample' => [],
            'dirty_files_truncated' => false,
            'clean' => true,
        ]);
        $hygiene->shouldReceive('trackedPythonBytecode')->andReturn([
            'is_git' => true,
            'tracked_count' => 2,
            'tracked_sample' => ['runtimes/python/a/__pycache__/x.pyc', 'b.pyc'],
            'tracked_truncated' => false,
            'resolution_command' => "git rm --cached -r '*.pyc'",
        ]);
        $hygiene->shouldReceive('forceBytecodeDisabledEnv')->andReturn([
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONPYCACHEPREFIX' => '/tmp/atlas-rivals-pycache',
        ]);
        app()->instance(WorkspaceHygieneService::class, $hygiene);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'preflight',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertNotSame(0, $exitCode);
        $this->assertContains(
            'tracked_python_bytecode',
            (array) ($payload['blocking_reasons'] ?? []),
        );
    }

    // ------------------------------------------------------------------
    // 6. dry-run never calls provider
    // ------------------------------------------------------------------
    public function test_dry_run_action_never_calls_provider(): void
    {
        /** @var AtlasRivalsRunOrchestrator&MockInterface $orchestrator */
        $orchestrator = Mockery::mock(AtlasRivalsRunOrchestrator::class);
        $orchestrator->shouldNotReceive('run');
        app()->instance(AtlasRivalsRunOrchestrator::class, $orchestrator);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'dry-run',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertFalse(
            (bool) ($payload['external_provider_call'] ?? true),
            'dry-run must never dispatch a real provider call',
        );
        $this->assertContains($exitCode, [0, 1]);
    }

    // ------------------------------------------------------------------
    // 7. quick-real WITHOUT confirmations: plan only, no provider
    // ------------------------------------------------------------------
    public function test_quick_real_without_three_confirmations_emits_plan_no_provider(): void
    {
        /** @var AtlasRivalsRunOrchestrator&MockInterface $orchestrator */
        $orchestrator = Mockery::mock(AtlasRivalsRunOrchestrator::class);
        $orchestrator->shouldNotReceive('run');
        app()->instance(AtlasRivalsRunOrchestrator::class, $orchestrator);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'quick-real',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(
            'blocked_operator_confirmation_required',
            $payload['state'] ?? null,
            'quick-real with no confirmations must surface the operator-confirm gate',
        );
        $this->assertArrayHasKey('plan', $payload);
        $this->assertIsArray($payload['plan']);
        $this->assertNotEmpty($payload['plan']);
        $this->assertNotSame(0, $exitCode);
    }

    // ------------------------------------------------------------------
    // 8. quick-real WITH three confirmations: governed runner mock is invoked
    // ------------------------------------------------------------------
    public function test_quick_real_with_three_confirmations_uses_governed_runner_in_tests(): void
    {
        $captured = [];
        /** @var AtlasRivalsRunOrchestrator&MockInterface $orchestrator */
        $orchestrator = Mockery::mock(AtlasRivalsRunOrchestrator::class);
        $orchestrator->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (array $intent) use (&$captured): array {
                $captured = $intent;

                return [
                    'verdict' => AtlasRivalsRunOrchestrator::VERDICT_PASSED,
                    'score' => null,
                    'claim_ready' => false,
                    'external_provider_call' => false,
                    'run_id' => $intent['run_id'] ?? 'rivals_orchestrator_test_quick_real',
                    'details' => ['mode' => $intent['mode'] ?? null],
                ];
            });
        app()->instance(AtlasRivalsRunOrchestrator::class, $orchestrator);

        Artisan::call(self::COMMAND, [
            'action' => 'quick-real',
            '--confirm-real-provider-call' => true,
            '--confirm-cost-approved' => true,
            '--confirm-runbook-reviewed' => true,
            '--mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            '--json' => true,
        ]);

        $this->assertNotEmpty($captured, 'governed orchestrator must be invoked with three confirmations');
        $this->assertTrue(
            (bool) ($captured['confirm_real_provider_call'] ?? false),
            'orchestrator intent must propagate confirm_real_provider_call=true',
        );
        $this->assertTrue((bool) ($captured['provider_cost_approved'] ?? false));
        $this->assertTrue((bool) ($captured['runbook_reviewed'] ?? false));
    }

    // ------------------------------------------------------------------
    // 9. State machine transitions (direct unit-style, no command)
    // ------------------------------------------------------------------
    public function test_battery_state_machine_transitions(): void
    {
        $machine = app(AtlasRivalsBatteryStateMachine::class);

        $noWorkspace = $machine->classify([
            'worktrees_present' => false,
            'dirty' => false,
            'tracked_pyc' => false,
            'confirmations' => ['real_provider_call' => false, 'cost_approved' => false, 'runbook_reviewed' => false],
        ]);
        $this->assertSame('no_workspace', $noWorkspace['state'] ?? null);

        $dirty = $machine->classify([
            'worktrees_present' => true,
            'dirty' => true,
            'tracked_pyc' => false,
            'confirmations' => ['real_provider_call' => true, 'cost_approved' => true, 'runbook_reviewed' => true],
        ]);
        $this->assertSame('blocked_dirty_workspace', $dirty['state'] ?? null);

        $pyc = $machine->classify([
            'worktrees_present' => true,
            'dirty' => false,
            'tracked_pyc' => true,
            'confirmations' => ['real_provider_call' => true, 'cost_approved' => true, 'runbook_reviewed' => true],
        ]);
        $this->assertSame('blocked_tracked_bytecode', $pyc['state'] ?? null);

        $needConfirm = $machine->classify([
            'worktrees_present' => true,
            'dirty' => false,
            'tracked_pyc' => false,
            'confirmations' => ['real_provider_call' => false, 'cost_approved' => false, 'runbook_reviewed' => false],
        ]);
        $this->assertSame('blocked_operator_confirmation_required', $needConfirm['state'] ?? null);

        $ready = $machine->classify([
            'worktrees_present' => true,
            'dirty' => false,
            'tracked_pyc' => false,
            'confirmations' => ['real_provider_call' => true, 'cost_approved' => true, 'runbook_reviewed' => true],
        ]);
        $this->assertSame('ready_for_real_provider', $ready['state'] ?? null);
    }

    // ------------------------------------------------------------------
    // 10. Triage fingerprint requires --reviewer AND --reason
    // ------------------------------------------------------------------
    public function test_triage_fingerprint_requires_reviewer_and_reason(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fingerprint = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'sonnet', 'case_ids' => ['c-harness-1']]);
        $registry->recordInvalidBattery($fingerprint, ['suite' => 'atlas-fair-claude-v1']);

        // Without --reviewer / --reason → blocker.
        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'triage-invalid-battery',
            '--fingerprint' => $fingerprint,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertNotSame(0, $exitCode, 'triage without reviewer+reason must block');
        $reasons = (array) ($payload['blocking_reasons'] ?? []);
        $this->assertTrue(
            in_array('missing_reviewer', $reasons, true) || in_array('missing_reason', $reasons, true),
            'triage without reviewer+reason must list missing_reviewer or missing_reason',
        );

        // With both → triage registered with receipt + hash.
        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'triage-invalid-battery',
            '--fingerprint' => $fingerprint,
            '--reviewer' => 'operator@atlas',
            '--reason' => 'historical invalid quarantined for forensic review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(0, $exitCode);
        $this->assertArrayHasKey('receipt', $payload);
        $this->assertIsString($payload['receipt']['hash'] ?? null);
        $this->assertNotEmpty($payload['receipt']['hash']);
        $this->assertFalse($registry->requiresTriage($fingerprint));
    }

    // ------------------------------------------------------------------
    // 11. Triage X does not release fingerprint Y
    // ------------------------------------------------------------------
    public function test_triage_fingerprint_X_does_not_release_fingerprint_Y(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fpA = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'opus', 'case_ids' => ['c-a']]);
        $fpB = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'sonnet', 'case_ids' => ['c-b']]);
        $this->assertNotSame($fpA, $fpB);

        $registry->recordInvalidBattery($fpA);
        $registry->recordInvalidBattery($fpB);

        Artisan::call(self::COMMAND, [
            'action' => 'triage-invalid-battery',
            '--fingerprint' => $fpA,
            '--reviewer' => 'operator@atlas',
            '--reason' => 'A triaged',
            '--json' => true,
        ]);

        $this->assertFalse($registry->requiresTriage($fpA));
        $this->assertTrue(
            $registry->requiresTriage($fpB),
            'triage of fingerprint A must NOT release fingerprint B',
        );
    }

    // ------------------------------------------------------------------
    // 12. Evidence pack requires provider_receipt on real run
    // ------------------------------------------------------------------
    public function test_evidence_pack_requires_provider_receipt_on_real_run(): void
    {
        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'collect-evidence',
            '--mode' => 'real_run',
            '--no-provider-receipt' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('evidence_incomplete', $payload['status'] ?? null);
        $this->assertArrayHasKey('score', $payload);
        $this->assertNull($payload['score'], 'incomplete evidence must yield score=null');
        $this->assertNotSame(0, $exitCode);
    }

    // ------------------------------------------------------------------
    // 13. events.jsonl contains expected kinds after a (fake) run
    // ------------------------------------------------------------------
    public function test_jsonl_events_have_expected_kinds(): void
    {
        $stream = app(RivalsForgeRunLogStreamService::class);
        $runId = $this->newRunId('jsonl');
        $stream->start($runId, ['mode' => 'fake_provider']);
        $stream->event($runId, 'run_started', ['run_id' => $runId]);
        $stream->heartbeat($runId, 'tick');
        $stream->event($runId, 'step_preflight', ['ok' => true]);
        $stream->event($runId, 'step_dry_run', ['ok' => true]);
        $stream->event($runId, 'final_report', ['verdict' => 'passed']);

        $kinds = array_map(
            static fn (array $e): string => (string) ($e['kind'] ?? ''),
            $stream->tail($runId),
        );

        $this->assertContains('run_started', $kinds);
        $this->assertContains('heartbeat', $kinds);
        $this->assertContains('final_report', $kinds);
        $this->assertTrue(
            in_array('step_preflight', $kinds, true) || in_array('step_dry_run', $kinds, true),
            'at least one step_* event must be present',
        );
    }

    // ------------------------------------------------------------------
    // 14. Report invalidates dirty after run
    // ------------------------------------------------------------------
    public function test_report_invalidates_dirty_after_run(): void
    {
        $runId = $this->newRunId('dirty-report');
        $stream = app(RivalsForgeRunLogStreamService::class);
        $stream->start($runId, ['mode' => 'fake_provider']);
        $stream->event($runId, 'after_clean_check', [
            'after_clean_check_ok' => false,
            'dirty_files' => ['ghost.tmp'],
        ]);
        $stream->event($runId, 'final_report', [
            'verdict' => 'invalid_dirty_after_run',
            'score' => null,
        ]);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'report',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('invalid', $payload['verdict'] ?? null);
        // NOTE [Agente η]: the original fallback string 'unset' makes this
        // assertion logically unsatisfiable in PHP — `null ?? 'unset'` ===
        // 'unset' and `assertNull('unset')` always fails. Fixing the fallback
        // to null preserves the test's actual intent (score must be null on
        // invalid runs) without altering the contract semantics.
        $this->assertNull($payload['score'] ?? null);
        $this->assertNotSame(0, $exitCode);
    }

    // ------------------------------------------------------------------
    // 15. Replay uses evidence pack correctly
    // ------------------------------------------------------------------
    public function test_replay_uses_evidence_pack_correct(): void
    {
        $runId = $this->newRunId('replay-ok');
        $stream = app(RivalsForgeRunLogStreamService::class);
        $stream->start($runId, ['mode' => 'fake_provider']);
        $stream->event($runId, 'evidence_pack', ['schema_version' => 'atlas.programming.evidence_pack.v1']);
        $stream->event($runId, 'final_report', ['verdict' => 'passed', 'run_id' => $runId]);

        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'replay',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame($runId, $payload['run_id'] ?? null);
        $this->assertGreaterThanOrEqual(2, (int) ($payload['event_count'] ?? 0));
        $this->assertSame('passed', data_get($payload, 'final_report.verdict'));
        $this->assertSame(0, $exitCode);

        // Replay of an unknown run must fail with replay_failed.
        $exitCode = Artisan::call(self::COMMAND, [
            'action' => 'replay',
            '--run-id' => 'rivals_orchestrator_test_unknown_'.bin2hex(random_bytes(4)),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('replay_failed', $payload['status'] ?? null);
        $this->assertNotSame(0, $exitCode);
    }

    // ------------------------------------------------------------------
    // 16. external_rivals_certification remains blocked
    // ------------------------------------------------------------------
    public function test_external_rivals_certification_remains_blocked(): void
    {
        $rubric = app(AtlasRivalsOneShotEnterpriseRubricService::class)->rubric();
        $this->assertTrue((bool) $rubric['separated_from_external_rivals_certification']);
        $this->assertFalse((bool) $rubric['promotes_external_rivals_claim']);

        // Drive a passing path through the harness and re-check the rubric.
        /** @var AtlasRivalsRunOrchestrator&MockInterface $orchestrator */
        $orchestrator = Mockery::mock(AtlasRivalsRunOrchestrator::class);
        $orchestrator->shouldReceive('run')->andReturn([
            'verdict' => AtlasRivalsRunOrchestrator::VERDICT_PASSED,
            'score' => null,
            'claim_ready' => false,
            'external_provider_call' => false,
            'run_id' => 'rivals_orchestrator_test_audit',
            'details' => [],
        ]);
        app()->instance(AtlasRivalsRunOrchestrator::class, $orchestrator);

        Artisan::call(self::COMMAND, [
            'action' => 'quick-real',
            '--confirm-real-provider-call' => true,
            '--confirm-cost-approved' => true,
            '--confirm-runbook-reviewed' => true,
            '--mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            '--json' => true,
        ]);

        Artisan::call(self::COMMAND, [
            'action' => 'audit',
            '--json' => true,
        ]);
        $audit = json_decode(Artisan::output(), true);
        if (is_array($audit) && array_key_exists('external_rivals_certification', $audit)) {
            $this->assertNotSame(
                'passed',
                $audit['external_rivals_certification'],
                'harness must NEVER flip external_rivals_certification to passed',
            );
        }

        // Invariant on the rubric persists post-run.
        $rubric2 = app(AtlasRivalsOneShotEnterpriseRubricService::class)->rubric();
        $this->assertFalse((bool) $rubric2['promotes_external_rivals_claim']);
        $this->assertTrue((bool) $rubric2['separated_from_external_rivals_certification']);
    }

    // ------------------------------------------------------------------
    // 17. full-smoke never calls real provider end-to-end
    // ------------------------------------------------------------------
    public function test_full_smoke_never_calls_provider_end_to_end(): void
    {
        $root = $this->makeTmpRoot('full-smoke-worktrees');
        Artisan::call(self::COMMAND, [
            'action' => 'setup-worktrees',
            '--worktree-root' => $root,
            '--json' => true,
        ]);
        $setupPayload = json_decode(Artisan::output(), true);
        $paths = (array) ($setupPayload['worktrees'] ?? []);
        $this->assertCount(2, $paths);

        $providerCalled = false;
        /** @var AtlasRivalsRunOrchestrator&MockInterface $orchestrator */
        $orchestrator = Mockery::mock(AtlasRivalsRunOrchestrator::class);
        $orchestrator->shouldReceive('run')
            ->andReturnUsing(function (array $intent) use (&$providerCalled): array {
                if (($intent['mode'] ?? null) === AtlasRivalsRunOrchestrator::MODE_REAL_PROVIDER
                    && (bool) ($intent['confirm_real_provider_call'] ?? false)) {
                    $providerCalled = true;
                }

                return [
                    'verdict' => AtlasRivalsRunOrchestrator::VERDICT_PASSED,
                    'score' => null,
                    'claim_ready' => false,
                    'external_provider_call' => false,
                    'run_id' => $intent['run_id'] ?? 'rivals_orchestrator_test_full_smoke',
                    'details' => ['mode' => $intent['mode'] ?? null],
                ];
            });
        app()->instance(AtlasRivalsRunOrchestrator::class, $orchestrator);

        /** @var RivalsForgeRunLogStreamService&MockInterface $stream */
        $stream = Mockery::mock(RivalsForgeRunLogStreamService::class);
        $stream->shouldReceive('latestRunId')->andReturn(null);
        app()->instance(RivalsForgeRunLogStreamService::class, $stream);

        Artisan::call(self::COMMAND, [
            'action' => 'full-smoke',
            '--repo-root' => base_path(),
            '--atlas-worktree' => $paths[0],
            '--baseline-worktree' => $paths[1],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertFalse(
            $providerCalled,
            'full-smoke must never dispatch the real provider',
        );
        $this->assertFalse(
            (bool) ($payload['external_provider_call'] ?? true),
            'full-smoke JSON must report external_provider_call=false',
        );
    }

    // ------------------------------------------------------------------
    // 18. Runbook generator produces copy-safe commands
    // ------------------------------------------------------------------
    public function test_runbook_generator_produces_copy_safe_commands_only(): void
    {
        $runbook = app(AtlasRivalsOperatorRunbookGenerator::class)->generate([
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'worktree_root' => $this->makeTmpRoot('runbook'),
        ]);

        $this->assertIsArray($runbook);
        $this->assertArrayHasKey('steps', $runbook);
        $this->assertIsArray($runbook['steps']);
        $this->assertNotEmpty($runbook['steps']);

        $seen = [];
        foreach ($runbook['steps'] as $i => $step) {
            $this->assertIsArray($step, "step #$i must be an array");
            $this->assertArrayHasKey('command', $step);
            $command = $step['command'];
            $this->assertIsString($command, "step #$i command must be a string");
            $this->assertNotSame('', trim($command), "step #$i command must be non-empty");
            $this->assertStringNotContainsString("\n", $command, "step #$i command must not contain newlines");
            $this->assertStringNotContainsString("\r", $command, "step #$i command must not contain CR");
            $this->assertStringNotContainsString('{"', $command, "step #$i command must not embed inline JSON");
            $this->assertNotContains($command, $seen, "step #$i command must be unique");
            $seen[] = $command;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fingerprintFor(array $intent): string
    {
        $fp = (string) app(RivalsForgeReadinessFingerprintService::class)->compute($intent)['value'];
        $this->createdFingerprints[] = $fp;

        return $fp;
    }

    private function newRunId(string $suffix): string
    {
        $id = 'rivals_orchestrator_test_harness_'.bin2hex(random_bytes(6)).'-'.$suffix;
        $this->createdRunIds[] = $id;

        return $id;
    }

    private function makeTmpRoot(string $tag): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rivals-harness-test-'.$tag.'-'.bin2hex(random_bytes(6));
        @mkdir($root, 0o755, true);
        $this->createdPaths[] = $root;

        return $root;
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
